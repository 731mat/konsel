<?php

declare(strict_types=1);

namespace App\Application\Meeting;

use App\Application\Meeting\Command\AssignOfficials;
use App\Application\Meeting\Command\SaveMeeting;
use App\Application\Shared\TransactionManager;
use App\Domain\Meeting\Meeting;
use App\Domain\Meeting\MeetingFilter;
use App\Domain\Meeting\MeetingRepository;
use App\Domain\Meeting\MeetingStatus;
use App\Domain\Meeting\MeetingTransition;
use App\Domain\Meeting\MeetingType;
use App\Domain\Municipality\MunicipalityRepository;
use App\Domain\People\Body;
use App\Domain\People\BodyRepository;
use App\Domain\People\Person;
use App\Domain\People\PersonRepository;
use App\Domain\Shared\Exception\InvalidStateException;
use App\Domain\Shared\Exception\ValidationException;
use Psr\Clock\ClockInterface;

/**
 * Zasedání: založení, úpravy, určení funkcí a přechody životního cyklu.
 */
final readonly class MeetingFacade
{
	public function __construct(
		private MeetingRepository $meetings,
		private BodyRepository $bodies,
		private PersonRepository $persons,
		private MunicipalityRepository $municipalities,
		private TransactionManager $transaction,
		private ClockInterface $clock,
	) {}

	public function create(int $bodyId, SaveMeeting $command): Meeting
	{
		return $this->transaction->run(function () use ($bodyId, $command): Meeting {
			$body = $this->bodies->get($bodyId);
			$number = $command->number ?? $this->meetings->getMaxNumber($body) + 1;
			$this->assertNumberAvailable($body, $number);
			$this->assertSingleConstituent($body, $command->type);

			$meeting = new Meeting($body, $number, $command->type, $command->scheduledAt, $command->place);
			$this->meetings->add($meeting);

			return $meeting;
		});
	}

	public function update(int $meetingId, SaveMeeting $command): void
	{
		$this->transaction->run(function () use ($meetingId, $command): void {
			$meeting = $this->meetings->get($meetingId);
			$renumber = $command->number !== null && $command->number !== $meeting->getNumber();
			if ($renumber) {
				$this->assertNumberAvailable($meeting->getBody(), $command->number);
			}

			$this->assertSingleConstituent($meeting->getBody(), $command->type, $meeting);
			$meeting->reschedule($command->type, $command->scheduledAt, $command->place);
			if ($renumber) {
				$meeting->renumber($command->number);
			}
		});
	}

	public function assignOfficials(int $meetingId, AssignOfficials $command): void
	{
		$this->transaction->run(function () use ($meetingId, $command): void {
			$this->meetings->get($meetingId)->assignOfficials(
				$this->findPerson($command->chairpersonId),
				$this->findPerson($command->recorderId),
				$this->findPerson($command->firstVerifierId),
				$this->findPerson($command->secondVerifierId),
			);
		});
	}

	/**
	 * @return list<string> upozornění, která přechod nezastaví (např. krátká lhůta pro úřední desku)
	 * @throws InvalidStateException přechod není z aktuálního stavu povolený
	 */
	public function transition(int $meetingId, MeetingTransition $transition): array
	{
		return $this->transaction->run(function () use ($meetingId, $transition): array {
			$meeting = $this->meetings->get($meetingId);
			$warnings = $transition === MeetingTransition::Share ? $this->getWarnings($meeting) : [];
			$meeting->transition($transition, $this->clock);

			return $warnings;
		});
	}

	public function correctTimes(int $meetingId, \DateTimeImmutable $startedAt, ?\DateTimeImmutable $endedAt): void
	{
		$this->transaction->run(function () use ($meetingId, $startedAt, $endedAt): void {
			$this->meetings->get($meetingId)->correctTimes($startedAt, $endedAt, $this->clock);
		});
	}

	/**
	 * @throws InvalidStateException zasedání už není v přípravě
	 */
	public function delete(int $meetingId): void
	{
		$this->transaction->run(function () use ($meetingId): void {
			$meeting = $this->meetings->get($meetingId);
			$meeting->assertDeletable();
			$this->meetings->remove($meeting);
		});
	}

	/**
	 * Upozornění k zasedání v přípravě (zobrazí se před sdílením i po něm).
	 *
	 * @return list<string>
	 */
	public function getWarnings(Meeting $meeting): array
	{
		$now = $this->clock->now();
		if ($meeting->getStatus() !== MeetingStatus::Draft || !$meeting->violatesNoticePeriod($now)) {
			return [];
		}

		$days = $meeting->daysUntil($now);
		$remaining = match (true) {
			$days === 0 => 'Zasedání je dnes.',
			$days === 1 => 'Do zasedání zbývá 1 den.',
			$days < 5 => "Do zasedání zbývají {$days} dny.",
			default => "Do zasedání zbývá {$days} dní.",
		};

		return [$remaining . ' Informaci o konání zasedání zastupitelstva je nutné zveřejnit na úřední desce alespoň '
			. Meeting::NoticePeriodDays . ' dní předem (§ 93 odst. 1 zákona o obcích).'];
	}

	public function get(int $meetingId): Meeting
	{
		return $this->meetings->get($meetingId);
	}

	/**
	 * @return list<Meeting>
	 */
	public function find(int $municipalityId, ?int $bodyId = null, ?int $year = null, ?MeetingStatus $status = null): array
	{
		return $this->meetings->findByFilter($this->municipalities->get($municipalityId), new MeetingFilter(
			$bodyId === null ? null : $this->bodies->get($bodyId),
			$year,
			$status,
		));
	}

	/**
	 * @return list<Meeting> připravovaná a probíhající zasedání od nejbližšího
	 */
	public function findUnfinished(int $municipalityId, int $limit = 5): array
	{
		return $this->meetings->findUnfinished($this->municipalities->get($municipalityId), $limit);
	}

	/**
	 * @return list<Meeting> proběhlá zasedání od posledního
	 */
	public function findFinished(int $municipalityId, int $limit = 5): array
	{
		return $this->meetings->findFinished($this->municipalities->get($municipalityId), $limit);
	}

	/**
	 * @return list<int>
	 */
	public function findYears(int $municipalityId): array
	{
		return $this->meetings->findYears($this->municipalities->get($municipalityId));
	}

	/**
	 * Místo posledního zasedání obce – předvyplní se do formuláře.
	 */
	public function suggestPlace(int $municipalityId): ?string
	{
		return $this->meetings->findLast($this->municipalities->get($municipalityId))?->getPlace();
	}

	private function assertNumberAvailable(Body $body, int $number): void
	{
		$existing = $this->meetings->findByBodyAndNumber($body, $number);
		if ($existing !== null) {
			throw new ValidationException("Číslo {$number} už má zasedání ze dne {$existing->getScheduledAt()->format('j. n. Y')}.", 'number');
		}
	}

	private function assertSingleConstituent(Body $body, MeetingType $type, ?Meeting $current = null): void
	{
		if ($type !== MeetingType::Constituent) {
			return;
		}

		$existing = $this->meetings->findConstituent($body);
		if ($existing !== null && $existing !== $current) {
			throw new ValidationException('Ustavující zasedání už v tomto volebním období existuje.', 'type');
		}
	}

	private function findPerson(?int $personId): ?Person
	{
		return $personId === null ? null : $this->persons->get($personId);
	}
}
