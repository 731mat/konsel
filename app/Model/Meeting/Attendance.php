<?php

declare(strict_types=1);

namespace App\Model\Meeting;

use App\Model\Municipality\Municipality;
use App\Model\People\Person;
use App\Model\Shared\Exception\InvalidStateException;
use App\Model\Shared\Exception\ValidationException;
use App\Model\Shared\IntegerId;
use App\Model\Shared\TenantOwned;
use Doctrine\ORM\Mapping as ORM;

/**
 * Docházka člena orgánu na zasedání. Pozdní příchod a předčasný odchod se zaznamenají časem
 * (do zápisu a pro hlasování – hlasovat může jen ten, kdo je v tu chvíli přítomen).
 */
#[ORM\Entity]
#[ORM\Table(name: 'attendance')]
#[ORM\UniqueConstraint(columns: ['meeting_id', 'person_id'])]
final class Attendance implements TenantOwned
{
	use IntegerId;

	public const int MaxNoteLength = 500;

	#[ORM\ManyToOne(targetEntity: Municipality::class)]
	#[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
	private Municipality $municipality;

	#[ORM\ManyToOne(targetEntity: Meeting::class)]
	#[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
	private Meeting $meeting;

	#[ORM\ManyToOne(targetEntity: Person::class)]
	#[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
	private Person $person;

	#[ORM\Column(length: 20, enumType: AttendanceStatus::class)]
	private AttendanceStatus $status = AttendanceStatus::Unknown;

	/**
	 * Pozdní příchod (po zahájení zasedání).
	 */
	#[ORM\Column(nullable: true)]
	private ?\DateTimeImmutable $arrivedAt = null;

	/**
	 * Odchod před koncem zasedání.
	 */
	#[ORM\Column(nullable: true)]
	private ?\DateTimeImmutable $leftAt = null;

	#[ORM\Column(length: 500, nullable: true)]
	private ?string $note = null;

	/**
	 * @throws InvalidStateException docházku zasedání už nelze měnit
	 * @throws ValidationException osoba není členem orgánu k datu zasedání
	 */
	public function __construct(Meeting $meeting, Person $person)
	{
		self::assertRecordable($meeting);
		if ($meeting->getBody()->findMembershipOf($person, $meeting->getScheduledAt()) === null) {
			throw new ValidationException("{$person->getFullName()} není k datu zasedání členem orgánu.", 'person');
		}

		$this->municipality = $meeting->getMunicipality();
		$this->meeting = $meeting;
		$this->person = $person;
	}

	/**
	 * Změna stavu. Přítomnost zapsaná až po zahájení zasedání se bere jako pozdní příchod.
	 */
	public function mark(AttendanceStatus $status, \DateTimeImmutable $now): void
	{
		self::assertRecordable($this->meeting);
		if ($status === $this->status) {
			return;
		}

		$startedAt = $this->meeting->getStartedAt();
		$this->arrivedAt = $status === AttendanceStatus::Present && $this->meeting->getStatus() === MeetingStatus::InProgress && $startedAt !== null && $now > $startedAt
			? $now
			: null;
		$this->leftAt = null;
		$this->status = $status;
	}

	/**
	 * Odchod přítomného člena během zasedání.
	 */
	public function leave(\DateTimeImmutable $now): void
	{
		self::assertRecordable($this->meeting);
		if ($this->status !== AttendanceStatus::Present || $this->leftAt !== null) {
			throw new InvalidStateException("{$this->person->getFullName()} není přítomen, odchod nelze zapsat.");
		}

		$this->leftAt = $now;
	}

	/**
	 * Návrat po odchodu – znovu přítomen od teď.
	 */
	public function return(\DateTimeImmutable $now): void
	{
		self::assertRecordable($this->meeting);
		if ($this->leftAt === null) {
			throw new InvalidStateException("{$this->person->getFullName()} neodešel, návrat nelze zapsat.");
		}

		$this->leftAt = null;
		$this->arrivedAt = $now;
	}

	public function changeNote(?string $note): void
	{
		self::assertRecordable($this->meeting);
		$note = $note === null ? null : trim($note);
		if ($note !== null && mb_strlen($note) > self::MaxNoteLength) {
			throw new ValidationException('Poznámka může mít nejvýše ' . self::MaxNoteLength . ' znaků.', 'note');
		}

		$this->note = $note === '' ? null : $note;
	}

	/**
	 * Je člen přítomen (v daném okamžiku, jinak teď)?
	 */
	public function isPresent(?\DateTimeImmutable $at = null): bool
	{
		if ($this->status !== AttendanceStatus::Present) {
			return false;
		}

		if ($at === null) {
			return $this->leftAt === null;
		}

		return ($this->arrivedAt === null || $this->arrivedAt <= $at) && ($this->leftAt === null || $this->leftAt > $at);
	}

	public static function canRecord(Meeting $meeting): bool
	{
		return in_array($meeting->getStatus(), [MeetingStatus::Shared, MeetingStatus::InProgress, MeetingStatus::Closed], true);
	}

	public function getMunicipality(): Municipality
	{
		return $this->municipality;
	}

	public function getMeeting(): Meeting
	{
		return $this->meeting;
	}

	public function getPerson(): Person
	{
		return $this->person;
	}

	public function getStatus(): AttendanceStatus
	{
		return $this->status;
	}

	public function getArrivedAt(): ?\DateTimeImmutable
	{
		return $this->arrivedAt;
	}

	public function getLeftAt(): ?\DateTimeImmutable
	{
		return $this->leftAt;
	}

	public function getNote(): ?string
	{
		return $this->note;
	}

	/**
	 * @throws InvalidStateException
	 */
	public static function assertRecordable(Meeting $meeting): void
	{
		if (!self::canRecord($meeting)) {
			throw new InvalidStateException($meeting->getStatus() === MeetingStatus::Draft
				? 'Docházku lze zapisovat až u sdíleného zasedání.'
				: 'Zápis je ověřen, docházku už nelze měnit.');
		}
	}
}
