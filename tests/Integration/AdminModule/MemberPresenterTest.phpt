<?php

declare(strict_types=1);

use App\Model\People\PeopleFacade;
use App\Model\User\Role;
use Nette\Application\BadRequestException;
use Tester\Assert;
use Tests\Support\ContainerFactory;
use Tests\Support\Fixtures;
use Tests\Support\PresenterTester;
use Tests\Support\TestDatabase;

require __DIR__ . '/../../bootstrap.php';

/**
 * @return array{PresenterTester, Fixtures, PeopleFacade, App\Model\Municipality\Municipality}
 */
function bootMembers(Role $role = Role::Admin): array
{
	$container = ContainerFactory::create();
	TestDatabase::migrate($container);
	$fixtures = new Fixtures($container);
	$municipality = $fixtures->municipality();
	$tester = new PresenterTester($container);
	$tester->loginAs($fixtures->user($municipality, role: $role));

	return [$tester, $fixtures, $container->getByType(PeopleFacade::class), $municipality];
}

test('admin builds council through forms: term, body, person, member', function (): void {
	[$tester, , $facade, $municipality] = bootMembers();

	PresenterTester::assertRedirect($tester->submitForm('Admin:Member', 'termForm', [
		'name' => '2026–2030',
		'startsOn' => '2026-10-01',
		'endsOn' => '',
	], action: 'termAdd'));
	$term = $facade->findCurrentTerm($municipality->getId());
	Assert::notNull($term);
	assert($term !== null);

	PresenterTester::assertRedirect($tester->submitForm('Admin:Member', 'bodyForm', [
		'type' => 'council',
		'name' => 'Zastupitelstvo obce Neplachovice',
		'seatCount' => '7',
		'numberingMask' => '{n}/{yyyy}',
		'numberingScope' => 'year',
	], action: 'bodyAdd', params: [
		'term' => $term->getId(),
	]));
	$body = $facade->findBodies($term->getId())[0];

	PresenterTester::assertRedirect($tester->submitForm('Admin:Person', 'personForm', [
		'titleBefore' => 'Ing.',
		'firstName' => 'Jan',
		'lastName' => 'Starosta',
		'titleAfter' => '',
		'email' => 'jan@obec.cz',
		'phone' => '',
	], action: 'add'));
	$person = $facade->findPersons($municipality->getId())[0];

	PresenterTester::assertRedirect($tester->submitForm('Admin:Member', 'memberForm', [
		'person' => (string) $person->getId(),
		'function' => 'mayor',
		'validFrom' => '2026-10-20',
		'validTo' => '',
	], action: 'body', params: [
		'id' => $body->getId(),
	]));

	$html = $tester->render('Admin:Member', 'body', [
		'id' => $body->getId(),
	]);
	Assert::contains('Ing. Jan Starosta', $html);
	Assert::contains('Starosta</td>', str_replace("\t", '', $html));
	Assert::contains('Zastupitelstvo obce Neplachovice', $tester->render('Admin:Member'));
});

test('domain rule violation is shown in member form', function (): void {
	[$tester, , $facade, $municipality] = bootMembers();
	$term = $facade->createTerm($municipality->getId(), '2026–2030', new DateTimeImmutable('2026-10-01'));
	$body = $facade->createBody($term->getId(), new App\Model\People\Command\SaveBody(App\Model\People\BodyType::Council, 'Zastupitelstvo', 7));
	$person = $facade->createPerson($municipality->getId(), new App\Model\People\Command\SavePerson('Jan', 'Novák'));

	$html = PresenterTester::renderResponse($tester->submitForm('Admin:Member', 'memberForm', [
		'person' => (string) $person->getId(),
		'function' => 'member',
		'validFrom' => '2025-01-01',
		'validTo' => '',
	], action: 'body', params: [
		'id' => $body->getId(),
	]));

	Assert::contains('Začátek členství musí spadat do volebního období orgánu.', $html);
});

test('reader can view bodies but cannot add members or edit', function (): void {
	[$tester, , $facade, $municipality] = bootMembers(Role::Reader);
	$term = $facade->createTerm($municipality->getId(), '2026–2030', new DateTimeImmutable('2026-10-01'));
	$body = $facade->createBody($term->getId(), new App\Model\People\Command\SaveBody(App\Model\People\BodyType::Council, 'Zastupitelstvo', 7));

	$html = $tester->render('Admin:Member', 'body', [
		'id' => $body->getId(),
	]);
	Assert::notContains('Přidat člena', $html);

	$e = Assert::exception(fn() => $tester->submitForm('Admin:Member', 'memberForm', [
		'person' => '1',
		'function' => 'member',
		'validFrom' => '2026-10-20',
	], action: 'body', params: [
		'id' => $body->getId(),
	]), BadRequestException::class);
	Assert::same(403, $e?->getHttpCode());

	$e = Assert::exception(fn() => $tester->run('Admin:Member', 'bodyEdit', [
		'id' => $body->getId(),
	]), BadRequestException::class);
	Assert::same(403, $e?->getHttpCode());
});

test('body of another municipality returns 404', function (): void {
	[$tester, $fixtures, $facade] = bootMembers();
	$other = $fixtures->municipality('Cizí obec');
	$term = $facade->createTerm($other->getId(), '2026–2030', new DateTimeImmutable('2026-10-01'));
	$foreignBody = $facade->createBody($term->getId(), new App\Model\People\Command\SaveBody(App\Model\People\BodyType::Council, 'Cizí zastupitelstvo', 7));

	$tester->render('Admin:Dashboard');
	$e = Assert::exception(fn() => $tester->run('Admin:Member', 'body', [
		'id' => $foreignBody->getId(),
	]), BadRequestException::class);
	Assert::same(404, $e?->getHttpCode());
});
