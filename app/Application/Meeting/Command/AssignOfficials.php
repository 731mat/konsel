<?php

declare(strict_types=1);

namespace App\Application\Meeting\Command;

/**
 * Předsedající, zapisovatel a ověřovatelé (ID osob, null = neurčeno).
 */
final readonly class AssignOfficials
{
	public function __construct(
		public ?int $chairpersonId = null,
		public ?int $recorderId = null,
		public ?int $firstVerifierId = null,
		public ?int $secondVerifierId = null,
	) {}
}
