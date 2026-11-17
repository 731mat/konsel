<?php

declare(strict_types=1);

use App\Model\Meeting\MeetingFacade;
use App\Model\Meeting\MeetingTransition;
use App\Model\People\Command\AddMember;
use App\Model\People\Command\SavePerson;
use App\Model\People\MemberFunction;
use App\Model\People\PeopleFacade;
use App\Model\User\Role;
use Nette\Application\BadRequestException;
use Tester\Assert;
use Tests\Support\ContainerFactory;
use Tests\Support\Fixtures;
use Tests\Support\MeetingScenario;
use Tests\Support\PresenterTester;
use Tests\Support\TestDatabase;

require __DIR__ . '/../../bootstrap.php';

/**
 * @param array<string, mixed> $params
 * @param array<mixed> $query
 * @param array<mixed> $post
 */
function proceedingsSignal(PresenterTester $tester, array $params, string $name, array $query, array $post): Nette\Application\Response
{
	/** @var array<string, mixed> $query */
	/** @var array<string, mixed> $post */
	return $tester->run('Admin:Proceedings', 'default', $params + $query + [
		'do' => $name,
	], post: $post);
}

test('clerk records attendance and sees the quorum change; reader only looks', function (): void {
	$container = ContainerFactory::create();
	TestDatabase::migrate($container);
	$scenario = MeetingScenario::create($container);
	$people = $container->getByType(PeopleFacade::class);
	$personIds = [];
	foreach (['Dvořák', 'Svobodová', 'Novotný'] as $lastName) {
		$person = $people->createPerson($scenario->municipality->getId(), new SavePerson('Člen', $lastName));
		$people->addMember($scenario->meeting->getBody()->getId(), new AddMember($person->getId(), MemberFunction::Member, new DateTimeImmutable('2026-10-21')));
		$personIds[] = $person->getId();
	}

	$container->getByType(MeetingFacade::class)->transition($scenario->meeting->getId(), MeetingTransition::Share);
	$fixtures = new Fixtures($container);
	$tester = new PresenterTester($container);
	$tester->loginAs($fixtures->user($scenario->municipality, 'editor@obec.cz', Role::Editor));
	$params = [
		'id' => $scenario->meeting->getId(),
	];

	$html = $tester->render('Admin:Proceedings', 'default', $params);
	Assert::contains('Není usnášeníschopné', $html);
	Assert::contains('přítomno 0 z 5, chybí 3', $html, 'zastupitelstvo má 5 míst, i když jsou obsazená jen 3');

	foreach ($personIds as $personId) {
		PresenterTester::assertRedirect($tester->run('Admin:Proceedings', 'default', $params + [
			'do' => 'mark',
			'personId' => $personId,
		], post: [
			'status' => 'present',
		]));
	}

	$html = $tester->render('Admin:Proceedings', 'default', $params);
	Assert::contains('Usnášeníschopné', $html);
	Assert::contains('přítomno 3 z 5, potřeba 3', $html);

	$e = Assert::exception(fn() => $tester->run('Admin:Proceedings', 'default', $params + [
		'do' => 'mark',
		'personId' => $personIds[0],
	], post: [
		'status' => 'unknown',
	]), BadRequestException::class);
	Assert::same(404, $e?->getHttpCode());

	$tester->loginAs($fixtures->user($scenario->municipality, 'ctenar@obec.cz', Role::Reader));
	$readOnly = $tester->render('Admin:Proceedings', 'default', $params);
	Assert::contains('Usnášeníschopné', $readOnly);
	Assert::notContains('name="status"', $readOnly);
	$e = Assert::exception(fn() => $tester->run('Admin:Proceedings', 'default', $params + [
		'do' => 'allPresent',
	], post: [
		'x' => '1',
	]), BadRequestException::class);
	Assert::same(403, $e?->getHttpCode());
});

test('clerk opens voting, records votes with one click and closes it with the result', function (): void {
	$container = ContainerFactory::create();
	TestDatabase::migrate($container);
	$scenario = MeetingScenario::create($container);
	$people = $container->getByType(PeopleFacade::class);
	$personIds = [];
	foreach (['Dvořák', 'Svobodová', 'Novotný'] as $lastName) {
		$person = $people->createPerson($scenario->municipality->getId(), new SavePerson('Člen', $lastName));
		$people->addMember($scenario->meeting->getBody()->getId(), new AddMember($person->getId(), MemberFunction::Member, new DateTimeImmutable('2026-10-21')));
		$personIds[] = $person->getId();
	}

	$meetings = $container->getByType(MeetingFacade::class);
	$meetings->transition($scenario->meeting->getId(), MeetingTransition::Share);
	$container->getByType(App\Model\Meeting\ProceedingsFacade::class)->markAllPresent($scenario->meeting->getId());
	$meetings->transition($scenario->meeting->getId(), MeetingTransition::Start);
	$tester = new PresenterTester($container);
	$tester->loginAs((new Fixtures($container))->user($scenario->municipality, 'editor@obec.cz', Role::Editor));
	$params = [
		'id' => $scenario->meeting->getId(),
	];
	$signal = static fn(string $name, array $query, array $post = [
		'x' => '1',
	]): Nette\Application\Response => proceedingsSignal($tester, $params, $name, $query, $post);

	PresenterTester::assertRedirect($signal('openVoting', [
		'itemId' => $scenario->item->getId(),
	], [
		'type' => 'proposal',
		'proposalText' => 'Zastupitelstvo obce Neplachovice schvaluje rozpočet na rok 2027.',
		'candidateId' => '',
	]));
	$round = $container->getByType(App\Model\Meeting\VotingFacade::class)->findByMeeting($scenario->meeting->getId())[$scenario->item->getId()][0];

	$open = $tester->render('Admin:Proceedings', 'default', $params);
	Assert::contains('Hlasování č. 1 – Návrh usnesení', $open);
	Assert::contains('k přijetí 3 pro z 5', $open);

	PresenterTester::assertRedirect($signal('allFor', [
		'roundId' => $round->getId(),
	]));
	PresenterTester::assertRedirect($signal('vote', [
		'roundId' => $round->getId(),
		'personId' => $personIds[2],
	], [
		'choice' => 'abstain',
	]));
	PresenterTester::assertRedirect($signal('closeVoting', [
		'roundId' => $round->getId(),
	]));

	$closed = $tester->render('Admin:Proceedings', 'default', $params);
	Assert::contains('<strong>2</strong> pro · <strong>0</strong> proti · <strong>1</strong> zdržel se', (string) preg_replace('~\s+~', ' ', $closed));
	Assert::contains('nepřijato', $closed, '2 pro z 5 členů nestačí');
	Assert::contains('Opravit hlasování', $closed);
});

test('discussion, minutes details and an item added during the meeting are saved from the proceedings screen', function (): void {
	$container = ContainerFactory::create();
	TestDatabase::migrate($container);
	$scenario = MeetingScenario::create($container);
	$meetings = $container->getByType(MeetingFacade::class);
	$meetings->transition($scenario->meeting->getId(), MeetingTransition::Share);
	$meetings->transition($scenario->meeting->getId(), MeetingTransition::Start);
	$tester = new PresenterTester($container);
	$tester->loginAs((new Fixtures($container))->user($scenario->municipality, 'editor@obec.cz', Role::Editor));
	$params = [
		'id' => $scenario->meeting->getId(),
	];

	PresenterTester::assertRedirect(proceedingsSignal($tester, $params, 'discussion', [
		'itemId' => $scenario->item->getId(),
	], [
		'discussion' => 'Paní Černá se ptala na výši rezervy.',
	]));

	Assert::contains('nemohlo být zahájeno v budoucnosti', PresenterTester::renderResponse($tester->submitForm('Admin:Proceedings', 'minutesForm', [
		'startedAt' => '18:05',
		'endedAt' => '',
		'guestCount' => '7',
		'minutesNote' => '',
	], params: $params)), 'testovací hodiny ukazují 10:00');
	PresenterTester::assertRedirect($tester->submitForm('Admin:Proceedings', 'minutesForm', [
		'startedAt' => '09:05',
		'endedAt' => '',
		'guestCount' => '7',
		'minutesNote' => 'Zasedání bylo veřejné.',
	], params: $params));

	PresenterTester::assertRedirect($tester->submitForm('Admin:Proceedings', 'addItemForm', [
		'title' => 'Příspěvek hasičům na výstroj',
		'proposedResolution' => 'Zastupitelstvo obce schvaluje příspěvek 20 000 Kč.',
		'position' => '',
	], params: $params));

	$meeting = $meetings->get($scenario->meeting->getId());
	Assert::same('Paní Černá se ptala na výši rezervy.', $meeting->getAgendaItems()[0]->getDiscussion());
	Assert::same(7, $meeting->getGuestCount());
	Assert::same('09:05', $meeting->getStartedAt()?->format('H:i'));
	Assert::true($meeting->getAgendaItems()[1]->isAddedDuringMeeting());

	$html = $tester->render('Admin:Proceedings', 'default', $params);
	Assert::contains('doplněno na zasedání', $html);
	Assert::contains('Paní Černá se ptala na výši rezervy.', $html);
	Assert::contains('value="7"', $html);
});
