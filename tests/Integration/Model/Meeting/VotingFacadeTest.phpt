<?php

declare(strict_types=1);

use App\Model\Meeting\AttendanceStatus;
use App\Model\Meeting\MeetingFacade;
use App\Model\Meeting\MeetingTransition;
use App\Model\Meeting\ProceedingsFacade;
use App\Model\Meeting\VoteChoice;
use App\Model\Meeting\VotingFacade;
use App\Model\Meeting\VotingResult;
use App\Model\Meeting\VotingType;
use App\Model\People\Command\AddMember;
use App\Model\People\Command\SavePerson;
use App\Model\People\MemberFunction;
use App\Model\People\PeopleFacade;
use App\Model\Shared\Exception\ValidationException;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\MockClock;
use Tester\Assert;
use Tests\Support\ContainerFactory;
use Tests\Support\MeetingScenario;
use Tests\Support\TestDatabase;

require __DIR__ . '/../../../bootstrap.php';

test('only members present at the moment of voting vote; result follows the majority of all members', function (): void {
	$container = ContainerFactory::create();
	TestDatabase::migrate($container);
	$scenario = MeetingScenario::create($container);
	$people = $container->getByType(PeopleFacade::class);
	$ids = [];
	foreach (['Dvořák', 'Svobodová', 'Novotný', 'Černá', 'Veselý'] as $lastName) {
		$person = $people->createPerson($scenario->municipality->getId(), new SavePerson('Člen', $lastName));
		$people->addMember($scenario->meeting->getBody()->getId(), new AddMember($person->getId(), MemberFunction::Member, new DateTimeImmutable('2026-10-21')));
		$ids[$lastName] = $person->getId();
	}

	$meetingId = $scenario->meeting->getId();
	$meetings = $container->getByType(MeetingFacade::class);
	$proceedings = $container->getByType(ProceedingsFacade::class);
	$voting = $container->getByType(VotingFacade::class);
	$clock = $container->getByType(ClockInterface::class);
	assert($clock instanceof MockClock);

	$meetings->transition($meetingId, MeetingTransition::Share);
	$proceedings->mark($meetingId, $ids['Veselý'], AttendanceStatus::Excused);
	$proceedings->markAllPresent($meetingId);
	$meetings->transition($meetingId, MeetingTransition::Start);

	$clock->sleep(600);
	Assert::exception(fn() => $voting->open($scenario->item->getId(), VotingType::Proposal, null), ValidationException::class, 'Napište znění návrhu, o kterém se hlasuje.');
	$round = $voting->open($scenario->item->getId(), VotingType::Proposal, 'Zastupitelstvo schvaluje rozpočet na rok 2027.');
	Assert::same(4, count($voting->voters($round)), 'omluvený Veselý nehlasuje');
	$voting->allFor($round->getId());
	$voting->cast($round->getId(), $ids['Černá'], VoteChoice::Against);

	$clock->sleep(60);
	$proceedings->mark($meetingId, $ids['Veselý'], AttendanceStatus::Present);
	Assert::exception(fn() => $voting->cast($round->getId(), $ids['Veselý'], VoteChoice::For), ValidationException::class, '%a%nebyl v okamžiku hlasování přítomen%a%');

	Assert::same(VotingResult::Adopted, $voting->close($round->getId())->result, '3 pro z 5 členů orgánu = nadpoloviční většina');
	$grouped = $voting->findByMeeting($meetingId);
	Assert::same([$scenario->item->getId()], array_keys($grouped));
	Assert::same([3, 1, 0, 0], [
		$grouped[$scenario->item->getId()][0]->count(VoteChoice::For),
		$grouped[$scenario->item->getId()][0]->count(VoteChoice::Against),
		$grouped[$scenario->item->getId()][0]->count(VoteChoice::Abstain),
		$grouped[$scenario->item->getId()][0]->count(VoteChoice::NotVoting),
	]);

	$second = $voting->open($scenario->item->getId(), VotingType::Amendment, 'Doplnit rezervu 200 tis. Kč.');
	Assert::same(5, count($voting->voters($second)), 'pozdě příchozí už hlasuje v dalším hlasování');
	$voting->delete($second->getId());
	Assert::count(1, $voting->findByMeeting($meetingId)[$scenario->item->getId()]);
});
