<?php

declare(strict_types=1);

use App\Application\Meeting\Command\AssignOfficials;
use App\Application\Meeting\Command\SaveMeeting;
use App\Application\Meeting\MeetingFacade;
use App\Application\People\Command\AddMember;
use App\Application\People\Command\SaveBody;
use App\Application\People\Command\SavePerson;
use App\Application\People\PeopleFacade;
use App\Domain\Meeting\Meeting;
use App\Domain\Meeting\MeetingStatus;
use App\Domain\Meeting\MeetingTransition;
use App\Domain\Meeting\MeetingType;
use App\Domain\People\Body;
use App\Domain\People\BodyType;
use App\Domain\People\MemberFunction;
use App\Domain\People\Person;
use App\Domain\Shared\Exception\InvalidStateException;
use App\Domain\Shared\Exception\ValidationException;
use Doctrine\ORM\EntityManagerInterface;
use Tester\Assert;
use Tests\Support\ContainerFactory;
use Tests\Support\Fixtures;
use Tests\Support\TestDatabase;

require __DIR__ . '/../../../bootstrap.php';

/**
 * Hodiny v testech: 2026-11-16 10:00 (config/test.neon).
 *
 * @return array{MeetingFacade, PeopleFacade, Body, list<Person>, Nette\DI\Container}
 */
function bootMeetings(): array
{
	$container = ContainerFactory::create();
	TestDatabase::migrate($container);
	$municipality = (new Fixtures($container))->municipality();
	$people = $container->getByType(PeopleFacade::class);
	$term = $people->createTerm($municipality->getId(), '2026–2030', new DateTimeImmutable('2026-10-03'), new DateTimeImmutable('2030-10-02'));
	$council = $people->createBody($term->getId(), new SaveBody(BodyType::Council, 'Zastupitelstvo obce Neplachovice', 5));
	$members = [];
	foreach (['Dvořák', 'Svobodová', 'Novotný'] as $i => $lastName) {
		$person = $people->createPerson($municipality->getId(), new SavePerson('Člen', $lastName));
		$people->addMember($council->getId(), new AddMember($person->getId(), $i === 0 ? MemberFunction::Mayor : MemberFunction::Member, new DateTimeImmutable('2026-10-21')));
		$members[] = $person;
	}

	return [$container->getByType(MeetingFacade::class), $people, $council, $members, $container];
}

/**
 * @param list<Meeting> $meetings
 * @return list<int>
 */
function meetingIds(array $meetings): array
{
	return array_map(static fn(Meeting $m): int => $m->getId(), $meetings);
}

function regular(string $scheduledAt, ?int $number = null, MeetingType $type = MeetingType::Regular): SaveMeeting
{
	return new SaveMeeting($type, new DateTimeImmutable($scheduledAt), 'Obecní dům', $number);
}

test('meetings are numbered automatically within the body and number must be unique', function (): void {
	[$facade, , $council] = bootMeetings();

	$first = $facade->create($council->getId(), regular('2026-10-21 18:00', type: MeetingType::Constituent));
	$second = $facade->create($council->getId(), regular('2026-12-09 18:00'));
	Assert::same([1, 2], [$first->getNumber(), $second->getNumber()]);
	Assert::same('2. zasedání', $second->getTitle());

	$e = Assert::exception(fn() => $facade->create($council->getId(), regular('2027-01-20 18:00', 2)), ValidationException::class, 'Číslo 2 už má zasedání ze dne 9. 12. 2026.');
	Assert::same('number', $e?->field);
	Assert::exception(fn() => $facade->update($second->getId(), regular('2026-12-09 18:00', 1)), ValidationException::class);
	Assert::noError(fn() => $facade->update($second->getId(), regular('2026-12-10 17:00', 5)));
	Assert::same(6, $facade->create($council->getId(), regular('2027-01-20 18:00'))->getNumber());
});

test('there is only one constituent meeting per body', function (): void {
	[$facade, , $council] = bootMeetings();
	$constituent = $facade->create($council->getId(), regular('2026-10-21 18:00', type: MeetingType::Constituent));

	Assert::exception(fn() => $facade->create($council->getId(), regular('2026-11-04 18:00', type: MeetingType::Constituent)), ValidationException::class, '%A%už v tomto volebním období existuje.');
	Assert::noError(fn() => $facade->update($constituent->getId(), regular('2026-10-22 18:00', type: MeetingType::Constituent)));
});

test('meeting goes through the lifecycle, sharing late warns about notice period', function (): void {
	[$facade, , $council, $members, $container] = bootMeetings();
	$meeting = $facade->create($council->getId(), regular('2026-11-20 18:00'));
	$facade->assignOfficials($meeting->getId(), new AssignOfficials($members[0]->getId(), null, $members[1]->getId(), $members[2]->getId()));

	Assert::count(1, $facade->getWarnings($meeting));
	$warnings = $facade->transition($meeting->getId(), MeetingTransition::Share);
	Assert::same(['Do zasedání zbývají 4 dny. Informaci o konání zasedání zastupitelstva je nutné zveřejnit na úřední desce alespoň 7 dní předem (§ 93 odst. 1 zákona o obcích).'], $warnings);
	Assert::same([], $facade->transition($meeting->getId(), MeetingTransition::Start));
	$facade->transition($meeting->getId(), MeetingTransition::Close);
	$facade->transition($meeting->getId(), MeetingTransition::ApproveMinutes);

	$container->getByType(EntityManagerInterface::class)->clear();
	$reloaded = $facade->get($meeting->getId());
	Assert::same(MeetingStatus::MinutesApproved, $reloaded->getStatus());
	Assert::same('2026-11-16 10:00', $reloaded->getStartedAt()?->format('Y-m-d H:i'));
	Assert::same(['Člen Svobodová', 'Člen Novotný'], array_map(static fn(Person $p): string => $p->getFullName(), $reloaded->getVerifiers()));
});

test('disallowed transition is rejected and nothing is persisted', function (): void {
	[$facade, , $council, , $container] = bootMeetings();
	$meeting = $facade->create($council->getId(), regular('2026-12-09 18:00'));

	Assert::exception(fn() => $facade->transition($meeting->getId(), MeetingTransition::Close), InvalidStateException::class);
	$container->getByType(EntityManagerInterface::class)->clear();
	Assert::same(MeetingStatus::Draft, $facade->get($meeting->getId())->getStatus());
});

test('only draft meetings can be deleted', function (): void {
	[$facade, , $council] = bootMeetings();
	$draft = $facade->create($council->getId(), regular('2026-12-09 18:00'));
	$shared = $facade->create($council->getId(), regular('2027-01-20 18:00'));
	$facade->transition($shared->getId(), MeetingTransition::Share);

	Assert::exception(fn() => $facade->delete($shared->getId()), InvalidStateException::class);
	$facade->delete($draft->getId());
	Assert::same([$shared->getId()], array_map(static fn($m) => $m->getId(), $facade->find($council->getMunicipality()->getId())));
});

test('list can be filtered by body, year and status; dashboard lists unfinished and finished', function (): void {
	[$facade, $people, $council] = bootMeetings();
	$municipalityId = $council->getMunicipality()->getId();
	$committee = $people->createBody($council->getTerm()->getId(), new SaveBody(BodyType::Committee, 'Finanční výbor', 3));

	$old = $facade->create($council->getId(), regular('2026-10-21 18:00', type: MeetingType::Constituent));
	foreach ([MeetingTransition::Share, MeetingTransition::Start, MeetingTransition::Close] as $transition) {
		$facade->transition($old->getId(), $transition);
	}

	$december = $facade->create($council->getId(), regular('2026-12-09 18:00'));
	$january = $facade->create($council->getId(), regular('2027-01-20 18:00'));
	$committeeMeeting = $facade->create($committee->getId(), regular('2026-11-25 17:00'));

	$ids = meetingIds(...);
	Assert::same($ids([$january, $december, $committeeMeeting, $old]), $ids($facade->find($municipalityId)));
	Assert::same($ids([$january, $december, $old]), $ids($facade->find($municipalityId, bodyId: $council->getId())));
	Assert::same($ids([$december, $committeeMeeting, $old]), $ids($facade->find($municipalityId, year: 2026)));
	Assert::same($ids([$old]), $ids($facade->find($municipalityId, status: MeetingStatus::Closed)));
	Assert::same([2027, 2026], $facade->findYears($municipalityId));

	Assert::same($ids([$committeeMeeting, $december]), $ids($facade->findUnfinished($municipalityId, 2)));
	Assert::same($ids([$old]), $ids($facade->findFinished($municipalityId)));
	Assert::same('Obecní dům', $facade->suggestPlace($municipalityId));
});

test('person who is a meeting official cannot be deleted', function (): void {
	[$facade, $people, $council] = bootMeetings();
	$clerk = $people->createPerson($council->getMunicipality()->getId(), new SavePerson('Pavla', 'Úřednice'));
	$meeting = $facade->create($council->getId(), regular('2026-12-09 18:00'));
	$facade->assignOfficials($meeting->getId(), new AssignOfficials(recorderId: $clerk->getId()));

	Assert::exception(fn() => $people->deletePerson($clerk->getId()), InvalidStateException::class, '%A%zapisovatel%A%');
});
