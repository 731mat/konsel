<?php

declare(strict_types=1);

namespace App\Model\Audit;

use App\Model\Municipality\Municipality;

interface AuditEntryRepository
{
	/**
	 * @return array{list<AuditEntry>, int} [stránka od nejnovější, celkový počet]
	 */
	public function find(Municipality $municipality, AuditFilter $filter, int $limit, int $offset): array;

	/**
	 * @return list<AuditEntry> od nejnovější
	 */
	public function findByMeeting(Municipality $municipality, int $meetingId): array;

	/**
	 * @param list<array{AuditEntityType, int}> $entities přímo zadané záznamy
	 * @param list<array{AuditEntityType, int}> $children záznamy s related_id (hlasy hlasování, úkoly usnesení)
	 * @return list<AuditEntry> od nejnovější
	 */
	public function findFor(Municipality $municipality, array $entities, array $children): array;

	/**
	 * @return array<int, string> ID uživatele → jméno (kdo v historii něco změnil)
	 */
	public function findActors(Municipality $municipality): array;
}
