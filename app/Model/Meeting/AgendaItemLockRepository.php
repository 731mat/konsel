<?php

declare(strict_types=1);

namespace App\Model\Meeting;

interface AgendaItemLockRepository
{
	public function findByItem(AgendaItem $item): ?AgendaItemLock;

	/**
	 * @return list<AgendaItemLock>
	 */
	public function findByMeeting(Meeting $meeting): array;

	public function add(AgendaItemLock $lock): void;

	public function remove(AgendaItemLock $lock): void;

	/**
	 * Smaže vypršelé zámky (všech obcí).
	 */
	public function removeExpired(\DateTimeImmutable $now): int;
}
