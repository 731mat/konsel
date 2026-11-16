<?php

declare(strict_types=1);

namespace App\Model\Meeting\Command;

final readonly class SaveAgendaItem
{
	public function __construct(
		public string $title,
		public ?string $description = null,
		public ?string $proposedResolution = null,
		public ?int $rapporteurId = null,
	) {}
}
