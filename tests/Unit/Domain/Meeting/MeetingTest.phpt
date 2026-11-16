<?php

declare(strict_types=1);

use App\Domain\Meeting\Meeting;
use App\Domain\Meeting\MeetingStatus;
use App\Domain\Meeting\MeetingTransition;
use App\Domain\Meeting\MeetingType;
use App\Domain\Municipality\Municipality;
use App\Domain\People\Body;
use App\Domain\People\BodyType;
use App\Domain\People\MemberFunction;
use App\Domain\People\Person;
use App\Domain\People\PersonName;
use App\Domain\People\Term;
use App\Domain\Shared\Exception\InvalidStateException;
use App\Domain\Shared\Exception\ValidationException;
use Symfony\Component\Clock\MockClock;
use Tester\Assert;

require __DIR__ . '/../../../bootstrap.php';

final class MeetingWorld
{
	public Municipality $municipality;

	public Term $term;

	public Body $council;

	/**
	 * @var list<Person>
	 */
	public array $members = [];

	public function __construct()
	{
		$this->municipality = new Municipality('Neplachovice', 'neplachovice');
		$this->term = new Term($this->municipality, '2026–2030', new DateTimeImmutable('2026-10-01'), new DateTimeImmutable('2030-09-30'));
		$this->council = new Body($this->term, BodyType::Council, 'Zastupitelstvo obce Neplachovice', 5);
		foreach (['Dvořák', 'Svobodová', 'Novotný', 'Černá', 'Veselý'] as $i => $lastName) {
			$person = $this->person('Člen', $lastName);
			$this->council->addMember($person, $i === 0 ? MemberFunction::Mayor : MemberFunction::Member, new DateTimeImmutable('2026-10-21'));
			$this->members[] = $person;
		}
	}

	public function person(string $first, string $last, ?Municipality $municipality = null): Person
	{
		return new Person($municipality ?? $this->municipality, new PersonName($first, $last));
	}

	public function meeting(string $scheduledAt = '2026-12-09 18:00', ?Body $body = null, MeetingType $type = MeetingType::Regular): Meeting
	{
		return new Meeting($body ?? $this->council, 3, $type, new DateTimeImmutable($scheduledAt), 'Obecní dům');
	}

	/**
	 * Zasedání posunuté po hlavní cestě automatu do požadovaného stavu.
	 */
	public function meetingIn(MeetingStatus $status, MockClock $clock): Meeting
	{
		$meeting = $this->meeting();
		$path = [MeetingTransition::Share, MeetingTransition::Start, MeetingTransition::Close, MeetingTransition::ApproveMinutes];
		foreach ($path as $transition) {
			if ($meeting->getStatus() === $status) {
				break;
			}

			$meeting->transition($transition, $clock);
		}

		Assert::same($status, $meeting->getStatus());

		return $meeting;
	}
}

test('meeting goes through the whole lifecycle and records times', function (): void {
	$world = new MeetingWorld();
	$clock = new MockClock('2026-12-01 09:00:00');
	$meeting = $world->meeting();
	Assert::same(MeetingStatus::Draft, $meeting->getStatus());

	$meeting->share($clock);
	Assert::same(MeetingStatus::Shared, $meeting->getStatus());
	Assert::same('2026-12-01 09:00', $meeting->getSharedAt()?->format('Y-m-d H:i'));

	$clock->modify('2026-12-09 18:02:00');
	$meeting->start($clock);
	Assert::same(MeetingStatus::InProgress, $meeting->getStatus());
	Assert::same('18:02', $meeting->getStartedAt()?->format('H:i'));

	$clock->modify('2026-12-09 20:15:00');
	$meeting->close($clock);
	Assert::same(MeetingStatus::Closed, $meeting->getStatus());
	Assert::same('20:15', $meeting->getEndedAt()?->format('H:i'));

	$meeting->approveMinutes();
	Assert::same(MeetingStatus::MinutesApproved, $meeting->getStatus());
	Assert::same([], $meeting->getAvailableTransitions());
});

test('every transition not allowed from the current status throws and keeps the status', function (): void {
	$world = new MeetingWorld();
	$clock = new MockClock('2026-12-01 09:00:00');
	$checked = 0;

	foreach (MeetingStatus::cases() as $status) {
		foreach (MeetingTransition::cases() as $transition) {
			$meeting = $world->meetingIn($status, $clock);
			if ($transition->source() === $status) {
				$meeting->transition($transition, $clock);
				Assert::same($transition->target(), $meeting->getStatus(), "{$status->value} --{$transition->value}-->");

				continue;
			}

			Assert::exception(
				fn() => $meeting->transition($transition, $clock),
				InvalidStateException::class,
				"Akci „{$transition->label()}“ nelze provést – zasedání je ve stavu „{$status->label()}“.",
			);
			Assert::same($status, $meeting->getStatus());
			$checked++;
		}
	}

	Assert::same(count(MeetingStatus::cases()) * count(MeetingTransition::cases()) - count(MeetingTransition::cases()), $checked);
});

test('available transitions follow the state machine', function (): void {
	$world = new MeetingWorld();
	$clock = new MockClock('2026-12-01 09:00:00');
	$expected = [
		'draft' => [MeetingTransition::Share],
		'shared' => [MeetingTransition::Start],
		'in_progress' => [MeetingTransition::Close],
		'closed' => [MeetingTransition::ApproveMinutes, MeetingTransition::Reopen],
		'minutes_approved' => [],
	];

	foreach (MeetingStatus::cases() as $status) {
		Assert::same($expected[$status->value], $world->meetingIn($status, $clock)->getAvailableTransitions(), $status->value);
	}
});

test('reopening a closed meeting clears end time and allows closing again', function (): void {
	$world = new MeetingWorld();
	$clock = new MockClock('2026-12-09 20:00:00');
	$meeting = $world->meetingIn(MeetingStatus::Closed, $clock);
	Assert::notNull($meeting->getEndedAt());

	$meeting->reopen();
	Assert::same(MeetingStatus::InProgress, $meeting->getStatus());
	Assert::null($meeting->getEndedAt());

	$clock->modify('2026-12-09 20:30:00');
	$meeting->close($clock);
	Assert::same('20:30', $meeting->getEndedAt()?->format('H:i'));
});

test('date, place, type and number can change only before the meeting starts', function (): void {
	$world = new MeetingWorld();
	$clock = new MockClock('2026-12-01 09:00:00');

	foreach ([MeetingStatus::Draft, MeetingStatus::Shared] as $status) {
		$meeting = $world->meetingIn($status, $clock);
		$meeting->reschedule(MeetingType::Extraordinary, new DateTimeImmutable('2026-12-10 17:30:45'), ' Hasičská zbrojnice ');
		$meeting->renumber(4);
		Assert::same('2026-12-10 17:30:00', $meeting->getScheduledAt()->format('Y-m-d H:i:s'));
		Assert::same('Hasičská zbrojnice', $meeting->getPlace());
		Assert::same(4, $meeting->getNumber());
		Assert::true($meeting->canReschedule());
	}

	foreach ([MeetingStatus::InProgress, MeetingStatus::Closed, MeetingStatus::MinutesApproved] as $status) {
		$meeting = $world->meetingIn($status, $clock);
		Assert::false($meeting->canReschedule());
		Assert::exception(fn() => $meeting->reschedule(MeetingType::Regular, new DateTimeImmutable('2026-12-10 18:00'), 'Jinde'), InvalidStateException::class);
		Assert::exception(fn() => $meeting->renumber(9), InvalidStateException::class);
		Assert::same('Obecní dům', $meeting->getPlace());
		Assert::same(3, $meeting->getNumber());
	}
});

test('meeting details are validated', function (): void {
	$world = new MeetingWorld();
	$board = new Body($world->term, BodyType::Board, 'Rada obce', 5);

	$e = Assert::exception(fn() => $world->meeting('2031-01-10 18:00'), ValidationException::class, '%A%volebního období 2026–2030.');
	Assert::same('scheduledAt', $e?->field);
	$e = Assert::exception(fn() => new Meeting($world->council, 1, MeetingType::Regular, new DateTimeImmutable('2026-12-09 18:00'), '  '), ValidationException::class);
	Assert::same('place', $e?->field);
	$e = Assert::exception(fn() => new Meeting($world->council, 0, MeetingType::Regular, new DateTimeImmutable('2026-12-09 18:00'), 'Obecní dům'), ValidationException::class);
	Assert::same('number', $e?->field);
	$e = Assert::exception(fn() => $world->meeting(body: $board, type: MeetingType::Constituent), ValidationException::class, 'Ustavující zasedání má jen zastupitelstvo.');
	Assert::same('type', $e?->field);
	Assert::noError(fn() => $world->meeting(type: MeetingType::Constituent));
});

test('officials: chairperson and verifiers must be members, verifiers distinct, recorder anyone from municipality', function (): void {
	$world = new MeetingWorld();
	[$mayor, $second, $third] = $world->members;
	$clerk = $world->person('Pavla', 'Úřednice');
	$meeting = $world->meeting();

	$meeting->assignOfficials($mayor, $clerk, $second, $third);
	Assert::same($mayor, $meeting->getChairperson());
	Assert::same($clerk, $meeting->getRecorder());
	Assert::same([$second, $third], $meeting->getVerifiers());

	$e = Assert::exception(fn() => $meeting->assignOfficials($clerk, null, null, null), ValidationException::class, 'Předsedající musí být k datu zasedání členem orgánu (Pavla Úřednice není).');
	Assert::same('chairperson', $e?->field);
	$e = Assert::exception(fn() => $meeting->assignOfficials($mayor, null, $clerk, null), ValidationException::class);
	Assert::same('firstVerifier', $e?->field);
	$e = Assert::exception(fn() => $meeting->assignOfficials($mayor, null, $second, $second), ValidationException::class, 'Ověřovatelé musí být dvě různé osoby.');
	Assert::same('secondVerifier', $e?->field);
	$e = Assert::exception(fn() => $meeting->assignOfficials($mayor, null, $second, $mayor), ValidationException::class, 'Ověřovatelem nemůže být předsedající.');
	Assert::same('secondVerifier', $e?->field);
	$e = Assert::exception(fn() => $meeting->assignOfficials(null, $world->person('Cizí', 'Osoba', new Municipality('Jiná', 'jina')), null, null), ValidationException::class);
	Assert::same('recorder', $e?->field);

	// neúspěšné pokusy nic nezměnily
	Assert::same($mayor, $meeting->getChairperson());
	Assert::same([$second, $third], $meeting->getVerifiers());

	$meeting->assignOfficials(null, null, null, null);
	Assert::same([], $meeting->getVerifiers());
});

test('member whose membership ended before the meeting cannot verify it', function (): void {
	$world = new MeetingWorld();
	$leaving = $world->members[4];
	$membership = $world->council->findMembershipOf($leaving, new DateTimeImmutable('2026-11-01'));
	assert($membership !== null);
	$world->council->endMembership($membership, new DateTimeImmutable('2026-11-30'));

	Assert::exception(fn() => $world->meeting('2026-12-09 18:00')->assignOfficials(null, null, $leaving, null), ValidationException::class);
	Assert::noError(fn() => $world->meeting('2026-11-25 18:00')->assignOfficials(null, null, $leaving, null));
});

test('officials can be changed until minutes are approved', function (): void {
	$world = new MeetingWorld();
	$clock = new MockClock('2026-12-01 09:00:00');

	foreach ([MeetingStatus::Draft, MeetingStatus::Shared, MeetingStatus::InProgress, MeetingStatus::Closed] as $status) {
		Assert::noError(fn() => $world->meetingIn($status, $clock)->assignOfficials($world->members[0], null, null, null));
	}

	$approved = $world->meetingIn(MeetingStatus::MinutesApproved, $clock);
	Assert::false($approved->canAssignOfficials());
	Assert::exception(fn() => $approved->assignOfficials($world->members[0], null, null, null), InvalidStateException::class);
});

test('notice period warning applies to council meetings shared less than 7 days ahead', function (): void {
	$world = new MeetingWorld();
	$meeting = $world->meeting('2026-12-09 18:00');

	Assert::false($meeting->violatesNoticePeriod(new DateTimeImmutable('2026-12-02 23:59')));
	Assert::same(7, $meeting->daysUntil(new DateTimeImmutable('2026-12-02 23:59')));
	Assert::true($meeting->violatesNoticePeriod(new DateTimeImmutable('2026-12-03 00:01')));
	Assert::true($meeting->violatesNoticePeriod(new DateTimeImmutable('2026-12-09 08:00')));
	Assert::false($meeting->violatesNoticePeriod(new DateTimeImmutable('2026-12-10 08:00')), 'zpětně zadané zasedání');
	Assert::same(-1, $meeting->daysUntil(new DateTimeImmutable('2026-12-10 08:00')));

	$board = new Body($world->term, BodyType::Board, 'Rada obce', 5);
	Assert::false($world->meeting('2026-12-09 18:00', $board)->violatesNoticePeriod(new DateTimeImmutable('2026-12-08 08:00')));
});

test('times can be corrected only for running or closed meetings', function (): void {
	$world = new MeetingWorld();
	$clock = new MockClock('2026-12-10 09:00:00');
	$closed = $world->meetingIn(MeetingStatus::Closed, $clock);

	$closed->correctTimes(new DateTimeImmutable('2026-12-09 18:00'), new DateTimeImmutable('2026-12-09 19:40'), $clock);
	Assert::same('18:00–19:40', $closed->getStartedAt()?->format('H:i') . '–' . $closed->getEndedAt()?->format('H:i'));

	Assert::exception(fn() => $closed->correctTimes(new DateTimeImmutable('2026-12-09 18:00'), null, $clock), ValidationException::class);
	Assert::exception(fn() => $closed->correctTimes(new DateTimeImmutable('2026-12-09 18:00'), new DateTimeImmutable('2026-12-09 17:00'), $clock), ValidationException::class);
	Assert::exception(fn() => $closed->correctTimes(new DateTimeImmutable('2026-12-11 18:00'), new DateTimeImmutable('2026-12-11 19:00'), $clock), ValidationException::class);
	Assert::exception(fn() => $world->meetingIn(MeetingStatus::Shared, $clock)->correctTimes(new DateTimeImmutable('2026-12-09 18:00'), null, $clock), InvalidStateException::class);
	Assert::exception(fn() => $world->meetingIn(MeetingStatus::InProgress, $clock)->correctTimes(new DateTimeImmutable('2026-12-09 18:00'), new DateTimeImmutable('2026-12-09 19:00'), $clock), ValidationException::class);
});

test('only draft meeting can be deleted', function (): void {
	$world = new MeetingWorld();
	$clock = new MockClock('2026-12-01 09:00:00');

	Assert::noError(fn() => $world->meetingIn(MeetingStatus::Draft, $clock)->assertDeletable());
	Assert::exception(fn() => $world->meetingIn(MeetingStatus::Shared, $clock)->assertDeletable(), InvalidStateException::class);
});

test('title reflects body and meeting type', function (): void {
	$world = new MeetingWorld();
	$board = new Body($world->term, BodyType::Board, 'Rada obce', 5);
	$committee = new Body($world->term, BodyType::Committee, 'Finanční výbor', 3);

	Assert::same('3. zasedání', $world->meeting()->getTitle());
	Assert::same('3. ustavující zasedání', $world->meeting(type: MeetingType::Constituent)->getTitle());
	Assert::same('3. mimořádné zasedání', $world->meeting(type: MeetingType::Extraordinary)->getTitle());
	Assert::same('3. mimořádná schůze', $world->meeting(body: $board, type: MeetingType::Extraordinary)->getTitle());
	Assert::same('3. jednání', $world->meeting(body: $committee)->getTitle());
});
