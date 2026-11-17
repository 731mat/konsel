<?php

declare(strict_types=1);

namespace App\Model\Resolution\Command;

final readonly class SaveResolutionTask
{
	public function __construct(
		public string $description,
		public ?int $assigneeId = null,
		public ?\DateTimeImmutable $dueDate = null,
	) {}
}
