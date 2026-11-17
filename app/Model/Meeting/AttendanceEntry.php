<?php

declare(strict_types=1);

namespace App\Model\Meeting;

use App\Model\People\MemberFunction;
use App\Model\People\Person;

/**
 * Řádek docházky pro zobrazení – i pro člena, u kterého se zatím nic nezapsalo.
 */
final readonly class AttendanceEntry
{
	public function __construct(
		public Person $person,
		public ?MemberFunction $function,
		public ?Attendance $attendance,
	) {}

	public function getStatus(): AttendanceStatus
	{
		return $this->attendance?->getStatus() ?? AttendanceStatus::Unknown;
	}

	public function isPresent(?\DateTimeImmutable $at = null): bool
	{
		return $this->attendance?->isPresent($at) ?? false;
	}

	public function hasLeft(): bool
	{
		return $this->attendance?->getLeftAt() !== null;
	}
}
