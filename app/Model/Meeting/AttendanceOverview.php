<?php

declare(strict_types=1);

namespace App\Model\Meeting;

/**
 * Docházka zasedání: členové orgánu v pořadí funkce a příjmení + usnášeníschopnost.
 */
final readonly class AttendanceOverview
{
	/**
	 * @param list<AttendanceEntry> $entries
	 */
	public function __construct(
		public array $entries,
		public Quorum $quorum,
	) {}

	public function count(AttendanceStatus $status): int
	{
		return count(array_filter($this->entries, static fn(AttendanceEntry $entry): bool => $entry->getStatus() === $status));
	}

	/**
	 * @return list<AttendanceEntry>
	 */
	public function present(?\DateTimeImmutable $at = null): array
	{
		return array_values(array_filter($this->entries, static fn(AttendanceEntry $entry): bool => $entry->isPresent($at)));
	}

	public function isComplete(): bool
	{
		return $this->count(AttendanceStatus::Unknown) === 0;
	}
}
