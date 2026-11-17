<?php

declare(strict_types=1);

use App\Model\Document\MeetingDocumentFacade;
use App\Model\Document\MeetingDocumentType;
use App\Model\Mail\InvitationFacade;
use App\Model\Meeting\AgendaFacade;
use App\Model\Meeting\Command\AssignOfficials;
use App\Model\Meeting\Command\SaveAgendaItem;
use App\Model\Meeting\Command\SaveMeeting;
use App\Model\Meeting\MeetingFacade;
use App\Model\Meeting\MeetingTransition;
use App\Model\Meeting\MeetingType;
use App\Model\Meeting\ProceedingsFacade;
use App\Model\Meeting\VotingFacade;
use App\Model\Meeting\VotingType;
use App\Model\People\BodyType;
use App\Model\People\Command\AddMember;
use App\Model\People\Command\SaveBody;
use App\Model\People\MemberFunction;
use App\Model\People\NumberingScope;
use App\Model\People\PeopleFacade;
use App\Model\User\Role;
use Tester\Assert;
use Tests\Support\ContainerFactory;
use Tests\Support\Fixtures;
use Tests\Support\PresenterTester;
use Tests\Support\ResolutionScenario;
use Tests\Support\TestDatabase;

require __DIR__ . '/../../bootstrap.php';

test('board has its own meetings, own numbering series, board minutes and its own invitations; lists follow the body switcher', function (): void {
	$container = ContainerFactory::create();
	TestDatabase::migrate($container);
	$council = ResolutionScenario::create($container);
	Assert::same('1/2026', $council->resolutions[0]->getNumber());

	$people = $container->getByType(PeopleFacade::class);
	$board = $people->createBody($council->meeting->meeting->getBody()->getTerm()->getId(), new SaveBody(BodyType::Board, 'Rada obce Neplachovice', 3, 'RO/{n}/{yy}', NumberingScope::Year));
	[$mayor, $deputy, $member] = $council->members;
	foreach ([[$mayor, MemberFunction::Mayor], [$deputy, MemberFunction::DeputyMayor], [$member, MemberFunction::BoardMember]] as [$person, $function]) {
		$people->addMember($board->getId(), new AddMember($person->getId(), $function, new DateTimeImmutable('2026-10-21')));
	}

	$meetings = $container->getByType(MeetingFacade::class);
	$boardMeeting = $meetings->create($board->getId(), new SaveMeeting(MeetingType::Regular, new DateTimeImmutable('2026-12-02 16:00'), 'Kancelář starosty'));
	Assert::same('1. schůze', $boardMeeting->getTitle());
	$item = $container->getByType(AgendaFacade::class)->addItem($boardMeeting->getId(), new SaveAgendaItem('Příspěvek SDH', null, 'Rada obce schvaluje příspěvek SDH.'));
	$meetings->assignOfficials($boardMeeting->getId(), new AssignOfficials($mayor->getId(), null, $member->getId(), null));
	$meetings->transition($boardMeeting->getId(), MeetingTransition::Share);

	$invitations = $container->getByType(InvitationFacade::class)->send($boardMeeting->getId(), static fn(string $t): string => "https://konsel.test/odkaz/{$t}");
	Assert::count(3, $invitations->sent, 'pozvánky jen třem členům rady, ne celému zastupitelstvu');

	$container->getByType(ProceedingsFacade::class)->markAllPresent($boardMeeting->getId());
	$meetings->transition($boardMeeting->getId(), MeetingTransition::Start);
	$voting = $container->getByType(VotingFacade::class);
	$round = $voting->open($item->getId(), VotingType::Proposal);
	$voting->allFor($round->getId());
	Assert::same('RO/1/26', $voting->close($round->getId())->resolution?->getNumber(), 'rada má vlastní řadu');
	$meetings->transition($boardMeeting->getId(), MeetingTransition::Close);

	$minutes = (string) preg_replace('~\s+~u', ' ', $container->getByType(MeetingDocumentFacade::class)->renderHtml($meetings->get($boardMeeting->getId()), MeetingDocumentType::Minutes));
	Assert::contains('z 1. schůze Rady obce Neplachovice', $minutes);
	Assert::contains('Rada obce Neplachovice je usnášeníschopná (§ 101 odst. 2', $minutes);
	Assert::contains('určený člen rady', $minutes);
	Assert::notContains('ověřovatel zápisu', $minutes);

	$tester = new PresenterTester($container);
	$tester->loginAs((new Fixtures($container))->user($council->meeting->municipality, role: Role::Editor));
	PresenterTester::assertRedirect($tester->run('Admin:Meeting', 'default', [
		'do' => 'selectBody',
		'bodyId' => $board->getId(),
	]));
	$list = $tester->render('Admin:Meeting');
	Assert::contains('1. schůze', $list);
	Assert::notContains('1. zasedání', $list, 'přepínač zobrazí jen schůze rady');
	Assert::contains('RO/1/26', $tester->render('Admin:Resolution'));
	Assert::notContains('>1/2026<', $tester->render('Admin:Resolution'));
	Assert::contains('1. zasedání', $tester->render('Admin:Meeting', 'default', [
		'body' => '',
	]), 'prázdný filtr = všechny orgány');
});
