<?php

declare(strict_types=1);

use App\Model\Meeting\Attendance;
use App\Model\Meeting\AttendanceStatus;
use App\Model\Meeting\Meeting;
use App\Model\Meeting\MeetingType;
use App\Model\Municipality\Municipality;
use App\Model\People\Body;
use App\Model\People\BodyType;
use App\Model\People\MemberFunction;
use App\Model\People\Person;
use App\Model\People\PersonName;
use App\Model\People\Term;
use App\Model\Shared\Exception\InvalidStateException;
use App\Model\Shared\Exception\ValidationException;
use Symfony\Component\Clock\MockClock;
use Tester\Assert;

require __DIR__ . '/../../../bootstrap.php';

/**
 * @return array{Meeting, Person, MockClock}
 */
function attendanceMeeting(): array
{
	$municipality = new Municipality('Neplachovice', 'neplachovice');
	$council = new Body(new Term($municipality, '2026–2030', new DateTimeImmutable('2026-10-01')), BodyType::Council, 'Zastupitelstvo obce Neplachovice', 5);
	$member = new Person($municipality, new PersonName('Jan', 'Dvořák'));
	$council->addMember($member, MemberFunction::Mayor, new DateTimeImmutable('2026-10-21'));

	return [new Meeting($council, 3, MeetingType::Regular, new DateTimeImmutable('2026-12-09 18:00'), 'Obecní dům'), $member, new MockClock('2026-12-09 17:30', 'Europe/Prague')];
}

test('attendance is recorded from sharing until minutes are approved, only for members', function (): void {
	[$meeting, $member, $clock] = attendanceMeeting();
	Assert::exception(fn() => new Attendance($meeting, $member), InvalidStateException::class, '%a%sdíleného%a%');

	$meeting->share($clock);
	$outsider = new Person($meeting->getMunicipality(), new PersonName('Host', 'Hostovský'));
	$e = Assert::exception(fn() => new Attendance($meeting, $outsider), ValidationException::class);
	Assert::same('person', $e?->field);

	$attendance = new Attendance($meeting, $member);
	Assert::same(AttendanceStatus::Unknown, $attendance->getStatus());
	$attendance->mark(AttendanceStatus::Excused, $clock->now());
	Assert::false($attendance->isPresent());

	$meeting->start($clock);
	$meeting->close($clock);
	$meeting->approveMinutes();
	Assert::exception(fn() => $attendance->mark(AttendanceStatus::Present, $clock->now()), InvalidStateException::class, '%a%ověřen%a%');
});

test('presence after start is a late arrival; leaving and returning are timed', function (): void {
	[$meeting, $member, $clock] = attendanceMeeting();
	$meeting->share($clock);
	$attendance = new Attendance($meeting, $member);
	$attendance->mark(AttendanceStatus::Present, $clock->now());
	Assert::null($attendance->getArrivedAt(), 'přítomen od začátku');

	$clock->modify('2026-12-09 18:00');
	$meeting->start($clock);
	$attendance->mark(AttendanceStatus::Excused, $clock->now());
	$clock->modify('2026-12-09 18:20');
	$attendance->mark(AttendanceStatus::Present, $clock->now());
	Assert::same('18:20', $attendance->getArrivedAt()?->format('H:i'));
	Assert::false($attendance->isPresent(new DateTimeImmutable('2026-12-09 18:10')), 'při hlasování v 18:10 ještě nebyl');
	Assert::true($attendance->isPresent(new DateTimeImmutable('2026-12-09 18:30')));

	$clock->modify('2026-12-09 19:40');
	$attendance->leave($clock->now());
	Assert::false($attendance->isPresent());
	Assert::true($attendance->isPresent(new DateTimeImmutable('2026-12-09 19:00')));
	Assert::exception(fn() => $attendance->leave($clock->now()), InvalidStateException::class);

	$clock->modify('2026-12-09 19:50');
	$attendance->return($clock->now());
	Assert::true($attendance->isPresent());
	Assert::null($attendance->getLeftAt());
	Assert::exception(fn() => $attendance->return($clock->now()), InvalidStateException::class);
});
