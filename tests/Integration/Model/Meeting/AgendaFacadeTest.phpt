<?php

declare(strict_types=1);

use App\Model\Meeting\AgendaFacade;
use App\Model\Meeting\AgendaItem;
use App\Model\Meeting\Command\AssignOfficials;
use App\Model\Meeting\Command\SaveAgendaItem;
use App\Model\Meeting\Command\SaveMeeting;
use App\Model\Meeting\Meeting;
use App\Model\Meeting\MeetingFacade;
use App\Model\Meeting\MeetingType;
use App\Model\People\BodyType;
use App\Model\People\Command\AddMember;
use App\Model\People\Command\SaveBody;
use App\Model\People\Command\SavePerson;
use App\Model\People\MemberFunction;
use App\Model\People\PeopleFacade;
use App\Model\Shared\Exception\ValidationException;
use Doctrine\ORM\EntityManagerInterface;
use Tester\Assert;
use Tests\Support\ContainerFactory;
use Tests\Support\Fixtures;
use Tests\Support\TestDatabase;

require __DIR__ . '/../../../bootstrap.php';

/**
 * @return array{AgendaFacade, MeetingFacade, Meeting, PeopleFacade, EntityManagerInterface}
 */
function bootAgenda(): array
{
	$container = ContainerFactory::create();
	TestDatabase::migrate($container);
	$municipality = (new Fixtures($container))->municipality();
	$people = $container->getByType(PeopleFacade::class);
	$term = $people->createTerm($municipality->getId(), '2026–2030', new DateTimeImmutable('2026-10-03'));
	$council = $people->createBody($term->getId(), new SaveBody(BodyType::Council, 'Zastupitelstvo obce Neplachovice', 5));
	$meetings = $container->getByType(MeetingFacade::class);
	$meeting = $meetings->create($council->getId(), new SaveMeeting(MeetingType::Regular, new DateTimeImmutable('2026-12-09 18:00'), 'Obecní dům'));

	return [$container->getByType(AgendaFacade::class), $meetings, $meeting, $people, $container->getByType(EntityManagerInterface::class)];
}

/**
 * @return list<string>
 */
function programOf(Meeting $meeting): array
{
	return array_map(static fn(AgendaItem $item): string => "{$item->getPosition()}. {$item->getTitle()}", $meeting->getAgendaItems());
}

test('standard items wrap the program and are not duplicated', function (): void {
	[$agenda, $meetings, $meeting, , $em] = bootAgenda();
	$agenda->addItem($meeting->getId(), new SaveAgendaItem('Rozpočtové opatření č. 5/2026', proposedResolution: 'Zastupitelstvo schvaluje RO č. 5/2026.'));

	Assert::same(5, $agenda->insertStandardItems($meeting->getId()));
	Assert::same(0, $agenda->insertStandardItems($meeting->getId()));

	$em->clear();
	Assert::same([
		'1. Zahájení',
		'2. Určení ověřovatelů zápisu a zapisovatele',
		'3. Schválení programu',
		'4. Rozpočtové opatření č. 5/2026',
		'5. Diskuse',
		'6. Závěr',
	], programOf($meetings->get($meeting->getId())));
});

test('standard verifier item uses assigned officials', function (): void {
	[$agenda, $meetings, $meeting, $people] = bootAgenda();
	$body = $meeting->getBody();
	$ids = [];
	foreach (['Dvořák', 'Svobodová', 'Novotný'] as $lastName) {
		$person = $people->createPerson($body->getMunicipality()->getId(), new SavePerson('Jan', $lastName));
		$people->addMember($body->getId(), new AddMember($person->getId(), MemberFunction::Member, new DateTimeImmutable('2026-10-21')));
		$ids[] = $person->getId();
	}

	$meetings->assignOfficials($meeting->getId(), new AssignOfficials(null, $ids[0], $ids[1], $ids[2]));
	$agenda->insertStandardItems($meeting->getId());

	Assert::same(
		'Zastupitelstvo obce Neplachovice určuje ověřovateli zápisu Jan Svobodová a Jan Novotný a zapisovatelem Jan Dvořák.',
		$meetings->get($meeting->getId())->getAgendaItems()[1]->getProposedResolution(),
	);
});

test('reorder, move and update persist; invalid order is rejected', function (): void {
	[$agenda, $meetings, $meeting, , $em] = bootAgenda();
	$a = $agenda->addItem($meeting->getId(), new SaveAgendaItem('A'));
	$b = $agenda->addItem($meeting->getId(), new SaveAgendaItem('B'));
	$c = $agenda->addItem($meeting->getId(), new SaveAgendaItem('C'));

	$agenda->reorder($meeting->getId(), [$c->getId(), $a->getId(), $b->getId()]);
	$agenda->moveItem($b->getId(), 2);
	$agenda->updateItem($a->getId(), new SaveAgendaItem('A – upraveno', 'Popis'));
	Assert::exception(fn() => $agenda->reorder($meeting->getId(), [$a->getId(), $b->getId()]), ValidationException::class);

	$em->clear();
	Assert::same(['1. C', '2. B', '3. A – upraveno'], programOf($meetings->get($meeting->getId())));

	$agenda->removeItem($b->getId());
	$em->clear();
	Assert::same(['1. C', '2. A – upraveno'], programOf($meetings->get($meeting->getId())));
});
