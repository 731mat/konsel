<?php

declare(strict_types=1);

namespace App\Model\Ai;

/**
 * Rozepsaný bod programu z formuláře (ještě neuložený) – podklad pro návrh textu.
 */
final readonly class AgendaDraft
{
	public function __construct(
		public string $title,
		public ?string $description = null,
		public ?string $proposedResolution = null,
		public ?int $parentId = null,
	) {}
}
