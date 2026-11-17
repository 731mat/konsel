<?php

declare(strict_types=1);

namespace App\Model\Meeting;

use App\Model\People\Person;
use App\Model\People\PersonRepository;
use App\Model\Shared\Exception\InvalidStateException;
use App\Model\Shared\Exception\ValidationException;
use App\Model\Shared\TransactionManager;
use Psr\Clock\ClockInterface;

/**
 * Průběh zasedání: docházka a usnášeníschopnost (hlasování a diskuse navazují).
 */
final readonly class ProceedingsFacade
{
	public function __construct(
		private MeetingRepository $meetings,
		private AgendaItemRepository $items,
		private PersonRepository $persons,
		private AttendanceRepository $attendances,
		private TransactionManager $transaction,
		private ClockInterface $clock,
	) {}

	public function attendance(int $meetingId, ?\DateTimeImmutable $at = null): AttendanceOverview
	{
		return $this->overview($this->meetings->get($meetingId), $at);
	}

	/**
	 * @throws InvalidStateException
	 * @throws ValidationException osoba není členem orgánu
	 */
	public function mark(int $meetingId, int $personId, AttendanceStatus $status): void
	{
		$this->transaction->run(function () use ($meetingId, $personId, $status): void {
			$meeting = $this->meetings->get($meetingId);
			$this->row($meeting, $this->persons->get($personId))->mark($status, $this->clock->now());
		});
	}

	/**
	 * Všichni, u koho zatím nic není zapsáno, jsou přítomni.
	 *
	 * @throws InvalidStateException
	 */
	public function markAllPresent(int $meetingId): void
	{
		$this->transaction->run(function () use ($meetingId): void {
			$meeting = $this->meetings->get($meetingId);
			foreach ($this->overview($meeting)->entries as $entry) {
				if ($entry->getStatus() === AttendanceStatus::Unknown) {
					$this->row($meeting, $entry->person)->mark(AttendanceStatus::Present, $this->clock->now());
				}
			}
		});
	}

	/**
	 * @throws InvalidStateException
	 */
	public function leave(int $meetingId, int $personId): void
	{
		$this->transaction->run(function () use ($meetingId, $personId): void {
			$this->row($this->meetings->get($meetingId), $this->persons->get($personId))->leave($this->clock->now());
		});
	}

	/**
	 * @throws InvalidStateException
	 */
	public function return(int $meetingId, int $personId): void
	{
		$this->transaction->run(function () use ($meetingId, $personId): void {
			$this->row($this->meetings->get($meetingId), $this->persons->get($personId))->return($this->clock->now());
		});
	}

	/**
	 * @throws InvalidStateException
	 * @throws ValidationException
	 */
	public function recordDiscussion(int $itemId, ?string $discussion): void
	{
		$this->transaction->run(function () use ($itemId, $discussion): void {
			$item = $this->items->get($itemId);
			$item->getMeeting()->recordDiscussion($item, $discussion);
		});
	}

	/**
	 * @throws InvalidStateException
	 * @throws ValidationException
	 */
	public function recordMinutesDetails(int $meetingId, ?int $guestCount, ?string $note): void
	{
		$this->transaction->run(function () use ($meetingId, $guestCount, $note): void {
			$this->meetings->get($meetingId)->recordMinutesDetails($guestCount, $note);
		});
	}

	private function overview(Meeting $meeting, ?\DateTimeImmutable $at = null): AttendanceOverview
	{
		$rows = [];
		foreach ($this->attendances->findByMeeting($meeting) as $attendance) {
			$rows[$attendance->getPerson()->getId()] = $attendance;
		}

		$entries = [];
		foreach ($meeting->getBody()->getMembersAt($meeting->getScheduledAt()) as $membership) {
			$person = $membership->getPerson();
			$entries[] = new AttendanceEntry($person, $membership->getFunction(), $rows[$person->getId()] ?? null);
			unset($rows[$person->getId()]);
		}

		foreach ($rows as $attendance) {
			$entries[] = new AttendanceEntry($attendance->getPerson(), null, $attendance);
		}

		$members = max($meeting->getBody()->getSeatCount(), count($entries));
		$present = count(array_filter($entries, static fn(AttendanceEntry $entry): bool => $entry->isPresent($at)));

		return new AttendanceOverview($entries, new Quorum($members, $present));
	}

	private function row(Meeting $meeting, Person $person): Attendance
	{
		foreach ($this->attendances->findByMeeting($meeting) as $attendance) {
			if ($attendance->getPerson() === $person) {
				return $attendance;
			}
		}

		$attendance = new Attendance($meeting, $person);
		$this->attendances->add($attendance);

		return $attendance;
	}
}
