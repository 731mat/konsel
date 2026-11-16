<?php

declare(strict_types=1);

use App\Model\People\BodyType;
use App\Model\People\Command\AddMember;
use App\Model\People\Command\SaveBody;
use App\Model\People\Command\SavePerson;
use App\Model\People\MemberFunction;
use App\Model\People\PeopleFacade;
use App\Model\Shared\Exception\InvalidStateException;
use App\Model\Shared\Exception\ValidationException;
use Doctrine\ORM\EntityManagerInterface;
use Tester\Assert;
use Tests\Support\ContainerFactory;
use Tests\Support\Fixtures;
use Tests\Support\TestDatabase;

require __DIR__ . '/../../../bootstrap.php';

/**
 * @return array{PeopleFacade, int, Nette\DI\Container}
 */
function bootPeople(): array
{
	$container = ContainerFactory::create();
	TestDatabase::migrate($container);
	$municipality = (new Fixtures($container))->municipality();

	return [$container->getByType(PeopleFacade::class), $municipality->getId(), $container];
}

test('council with members is persisted and reloaded in order', function (): void {
	[$facade, $municipalityId, $container] = bootPeople();
	$term = $facade->createTerm($municipalityId, '2026–2030', new DateTimeImmutable('2026-10-01'));
	$body = $facade->createBody($term->getId(), new SaveBody(BodyType::Council, 'Zastupitelstvo obce Neplachovice', 7));
	$mayor = $facade->createPerson($municipalityId, new SavePerson('Jan', 'Starosta', email: 'STAROSTA@obec.cz'));
	$member = $facade->createPerson($municipalityId, new SavePerson('Alena', 'Adamová'));
	$facade->addMember($body->getId(), new AddMember($member->getId(), MemberFunction::Member, new DateTimeImmutable('2026-10-20')));
	$facade->addMember($body->getId(), new AddMember($mayor->getId(), MemberFunction::Mayor, new DateTimeImmutable('2026-10-20')));

	$container->getByType(EntityManagerInterface::class)->clear();
	$reloaded = $facade->getBody($body->getId());
	$names = array_map(static fn($m) => $m->getPerson()->getFullName(), $reloaded->getMembersAt(new DateTimeImmutable('2026-11-16')));

	Assert::same(['Jan Starosta', 'Alena Adamová'], $names);
	Assert::same('starosta@obec.cz', $facade->getPerson($mayor->getId())->getEmail()?->value);
	Assert::same('2026–2030', $facade->findCurrentTerm($municipalityId)?->getName());
});

test('overlapping terms and duplicate council are rejected', function (): void {
	[$facade, $municipalityId] = bootPeople();
	$term = $facade->createTerm($municipalityId, '2026–2030', new DateTimeImmutable('2026-10-01'), new DateTimeImmutable('2030-09-30'));
	$facade->createBody($term->getId(), new SaveBody(BodyType::Council, 'Zastupitelstvo', 9));

	Assert::exception(fn() => $facade->createTerm($municipalityId, 'Překryv', new DateTimeImmutable('2030-01-01')), ValidationException::class);
	Assert::exception(fn() => $facade->createBody($term->getId(), new SaveBody(BodyType::Council, 'Druhé', 9)), ValidationException::class, '%A%už v tomto volebním období existuje.');
	Assert::noError(fn() => $facade->createBody($term->getId(), new SaveBody(BodyType::Committee, 'Finanční výbor', 3)));
	Assert::noError(fn() => $facade->createBody($term->getId(), new SaveBody(BodyType::Committee, 'Kontrolní výbor', 3)));
});

test('person with membership history cannot be deleted', function (): void {
	[$facade, $municipalityId] = bootPeople();
	$term = $facade->createTerm($municipalityId, '2026–2030', new DateTimeImmutable('2026-10-01'));
	$body = $facade->createBody($term->getId(), new SaveBody(BodyType::Council, 'Zastupitelstvo', 9));
	$person = $facade->createPerson($municipalityId, new SavePerson('Jan', 'Novák'));
	$loner = $facade->createPerson($municipalityId, new SavePerson('Petr', 'Bezčlenství'));
	$facade->addMember($body->getId(), new AddMember($person->getId(), MemberFunction::Member, new DateTimeImmutable('2026-10-20')));

	Assert::exception(fn() => $facade->deletePerson($person->getId()), InvalidStateException::class);
	Assert::noError(fn() => $facade->deletePerson($loner->getId()));
	Assert::count(1, $facade->findPersons($municipalityId));
});

test('structure warnings point out unusual composition', function (): void {
	[$facade, $municipalityId] = bootPeople();
	$term = $facade->createTerm($municipalityId, '2026–2030', new DateTimeImmutable('2026-10-01'));
	$facade->createBody($term->getId(), new SaveBody(BodyType::Council, 'Zastupitelstvo', 9));
	$facade->createBody($term->getId(), new SaveBody(BodyType::Board, 'Rada', 4));

	$warnings = implode("\n", $facade->getStructureWarnings($term->getId()));
	Assert::contains('alespoň 15 zastupiteli', $warnings);
	Assert::contains('nejméně 5', $warnings);
	Assert::contains('obsazeno 0 z 9', $warnings);
});
