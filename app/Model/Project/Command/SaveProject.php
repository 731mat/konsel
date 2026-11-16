<?php

declare(strict_types=1);

namespace App\Model\Project\Command;

use App\Model\Project\ProjectPriority;

final readonly class SaveProject
{
	public function __construct(
		public string $title,
		public ?string $description = null,
		public ProjectPriority $priority = ProjectPriority::Normal,
		public ?int $responsibleId = null,
		public ?int $estimatedCost = null,
	) {}
}
