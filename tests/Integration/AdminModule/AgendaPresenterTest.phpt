<?php

declare(strict_types=1);

use App\Model\Meeting\AgendaFacade;
use App\Model\Meeting\Command\SaveAgendaItem;
use App\Model\Meeting\Command\SaveMeeting;
use App\Model\Meeting\MeetingFacade;
use App\Model\Meeting\MeetingTransition;
use App\Model\Meeting\MeetingType;
use App\Model\Municipality\Municipality;
use App\Model\People\BodyType;
use App\Model\People\Command\SaveBody;
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
 * @return array{PresenterTester, AgendaFacade, MeetingFacade, int, Fixtures, PeopleFacade, Municipality}
 */
function bootAgendaUi(Role $role = Role::Editor): array
{
	$container = ContainerFactory::create();
	TestDatabase::migrate($container);
	$fixtures = new Fixtures($container);
	$municipality = $fixtures->municipality();
	$people = $container->getByType(PeopleFacade::class);
	$term = $people->createTerm($municipality->getId(), '2026–2030', new DateTimeImmutable('2026-10-03'));
	$council = $people->createBody($term->getId(), new SaveBody(BodyType::Council, 'Zastupitelstvo obce Neplachovice', 5));
	$meetings = $container->getByType(MeetingFacade::class);
	$meeting = $meetings->create($council->getId(), new SaveMeeting(MeetingType::Regular, new DateTimeImmutable('2026-12-09 18:00'), 'Obecní dům'));
	$tester = new PresenterTester($container);
	$tester->loginAs($fixtures->user($municipality, role: $role));

	return [$tester, $container->getByType(AgendaFacade::class), $meetings, $meeting->getId(), $fixtures, $people, $municipality];
}

test('editor adds an item through the form and sees it in the program', function (): void {
	[$tester, , $meetings, $meetingId] = bootAgendaUi();

	PresenterTester::assertRedirect($tester->submitForm('Admin:Agenda', 'itemForm', [
		'title' => 'Prodej pozemku p. č. 123/4',
		'rapporteur' => '',
		'description' => "Žadatel požádal o koupi.\n\nZáměr byl zveřejněn.",
		'proposedResolution' => 'Zastupitelstvo obce Neplachovice schvaluje prodej pozemku p. č. 123/4.',
	], action: 'add', params: [
		'meeting' => $meetingId,
	]));
	$item = $meetings->get($meetingId)->getAgendaItems()[0];

	$html = $tester->render('Admin:Agenda', 'detail', [
		'id' => $item->getId(),
	]);
	Assert::contains('<p>Žadatel požádal o koupi.</p><p>Záměr byl zveřejněn.</p>', $html);
	Assert::contains('schvaluje prodej pozemku', $html);
	$program = $tester->render('Admin:Meeting', 'detail', [
		'id' => $meetingId,
	]);
	Assert::contains('<span class="agenda-number">1.</span>', $program);
	Assert::contains('>Prodej pozemku p. č. 123/4</a>', $program);
});

test('program signals: standard items, move and AJAX reorder', function (): void {
	[$tester, $agenda, $meetings, $meetingId] = bootAgendaUi();
	$agenda->addItem($meetingId, new SaveAgendaItem('Rozpočet na rok 2027'));

	PresenterTester::assertRedirect($tester->run('Admin:Meeting', 'detail', [
		'id' => $meetingId,
		'do' => 'insertStandard',
	], post: [
		'x' => '1',
	]));
	$items = $meetings->get($meetingId)->getAgendaItems();
	Assert::same('Rozpočet na rok 2027', $items[3]->getTitle());

	PresenterTester::assertRedirect($tester->run('Admin:Meeting', 'detail', [
		'id' => $meetingId,
		'do' => 'moveItem',
		'itemId' => $items[3]->getId(),
		'position' => 1,
	], post: [
		'x' => '1',
	]));
	Assert::same('Rozpočet na rok 2027', $meetings->get($meetingId)->getAgendaItems()[0]->getTitle());

	$reversed = array_reverse(array_map(static fn($i) => (string) $i->getId(), $meetings->get($meetingId)->getAgendaItems()));
	PresenterTester::assertRedirect($tester->run('Admin:Meeting', 'detail', [
		'id' => $meetingId,
		'do' => 'reorder',
	], post: [
		'order' => $reversed,
	]));
	Assert::same('Závěr', $meetings->get($meetingId)->getAgendaItems()[0]->getTitle());
});

test('started meeting has locked program', function (): void {
	[$tester, $agenda, $meetings, $meetingId] = bootAgendaUi();
	$item = $agenda->addItem($meetingId, new SaveAgendaItem('Bod'));
	$meetings->transition($meetingId, MeetingTransition::Share);
	$meetings->transition($meetingId, MeetingTransition::Start);

	PresenterTester::assertRedirect($tester->run('Admin:Agenda', 'edit', [
		'id' => $item->getId(),
	]));
	Assert::notContains('Přidat bod', $tester->render('Admin:Meeting', 'detail', [
		'id' => $meetingId,
	]));
});

test('reader can view items but not change them', function (): void {
	[$tester, $agenda, , $meetingId] = bootAgendaUi(Role::Reader);
	$item = $agenda->addItem($meetingId, new SaveAgendaItem('Bod'));

	Assert::notContains('Upravit', $tester->render('Admin:Agenda', 'detail', [
		'id' => $item->getId(),
	]));
	foreach ([[
		'add', [
			'meeting' => $meetingId,
		]], [
			'edit', [
				'id' => $item->getId(),
			]]] as [$action, $params]) {
		$e = Assert::exception(fn() => $tester->run('Admin:Agenda', $action, $params), BadRequestException::class);
		Assert::same(403, $e?->getHttpCode(), $action);
	}
});

test('item of another municipality returns 404', function (): void {
	[$tester, $agenda, $meetings, , $fixtures, $people] = bootAgendaUi();
	$other = $fixtures->municipality('Cizí obec');
	$term = $people->createTerm($other->getId(), '2026–2030', new DateTimeImmutable('2026-10-03'));
	$body = $people->createBody($term->getId(), new SaveBody(BodyType::Council, 'Cizí', 5));
	$foreignMeeting = $meetings->create($body->getId(), new SaveMeeting(MeetingType::Regular, new DateTimeImmutable('2026-12-09 18:00'), 'Jinde'));
	$foreignItem = $agenda->addItem($foreignMeeting->getId(), new SaveAgendaItem('Cizí bod'));

	$tester->render('Admin:Dashboard');
	$e = Assert::exception(fn() => $tester->run('Admin:Agenda', 'detail', [
		'id' => $foreignItem->getId(),
	]), BadRequestException::class);
	Assert::same(404, $e?->getHttpCode());
});
