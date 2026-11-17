<?php

declare(strict_types=1);

use App\Model\Meeting\AttendanceStatus;
use App\Model\Meeting\MeetingFacade;
use App\Model\Meeting\MeetingTransition;
use App\Model\Meeting\ProceedingsFacade;
use App\Model\People\Command\AddMember;
use App\Model\People\Command\SavePerson;
use App\Model\People\MemberFunction;
use App\Model\People\PeopleFacade;
use App\Model\Shared\Exception\InvalidStateException;
use App\Model\Shared\Exception\ValidationException;
use Tester\Assert;
use Tests\Support\ContainerFactory;
use Tests\Support\MeetingScenario;
use Tests\Support\TestDatabase;

require __DIR__ . '/../../../bootstrap.php';

test('attendance of all members drives the quorum', function (): void {
	$container = ContainerFactory::create();
	TestDatabase::migrate($container);
	$scenario = MeetingScenario::create($container);
	$people = $container->getByType(PeopleFacade::class);
	$ids = [];
	foreach (['Dvořák', 'Svobodová', 'Novotný', 'Černá', 'Veselý'] as $i => $lastName) {
		$person = $people->createPerson($scenario->municipality->getId(), new SavePerson('Člen', $lastName));
		$people->addMember($scenario->meeting->getBody()->getId(), new AddMember($person->getId(), $i === 0 ? MemberFunction::Mayor : MemberFunction::Member, new DateTimeImmutable('2026-10-21')));
		$ids[$lastName] = $person->getId();
	}

	$guest = $people->createPerson($scenario->municipality->getId(), new SavePerson('Host', 'Hostovský'));
	$facade = $container->getByType(ProceedingsFacade::class);
	$meetingId = $scenario->meeting->getId();

	$overview = $facade->attendance($meetingId);
	Assert::count(5, $overview->entries);
	Assert::same('Člen Dvořák', $overview->entries[0]->person->getFullName(), 'starosta první');
	Assert::same(5, $overview->count(AttendanceStatus::Unknown));
	Assert::same(3, $overview->quorum->required);
	Assert::false($overview->quorum->isReached());
	Assert::exception(fn() => $facade->mark($meetingId, $ids['Dvořák'], AttendanceStatus::Present), InvalidStateException::class);

	$container->getByType(MeetingFacade::class)->transition($meetingId, MeetingTransition::Share);
	$facade->mark($meetingId, $ids['Dvořák'], AttendanceStatus::Present);
	$facade->mark($meetingId, $ids['Svobodová'], AttendanceStatus::Excused);
	$facade->mark($meetingId, $ids['Novotný'], AttendanceStatus::Present);
	Assert::false($facade->attendance($meetingId)->quorum->isReached(), '2 z 5 nestačí');

	$facade->markAllPresent($meetingId);
	$overview = $facade->attendance($meetingId);
	Assert::true($overview->isComplete());
	Assert::same(4, $overview->quorum->present, 'omluvená zůstala omluvená');
	Assert::true($overview->quorum->isReached());

	$container->getByType(MeetingFacade::class)->transition($meetingId, MeetingTransition::Start);
	$facade->leave($meetingId, $ids['Novotný']);
	$facade->leave($meetingId, $ids['Černá']);
	Assert::same(2, $facade->attendance($meetingId)->quorum->present);
	Assert::false($facade->attendance($meetingId)->quorum->isReached(), 'po odchodu dvou členů není usnášeníschopné');
	$facade->return($meetingId, $ids['Černá']);
	Assert::true($facade->attendance($meetingId)->quorum->isReached());

	$e = Assert::exception(fn() => $facade->mark($meetingId, $guest->getId(), AttendanceStatus::Present), ValidationException::class);
	Assert::contains('není k datu zasedání členem', (string) $e?->getMessage());
});
