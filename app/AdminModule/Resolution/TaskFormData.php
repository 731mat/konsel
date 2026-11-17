<?php

declare(strict_types=1);

namespace App\AdminModule\Resolution;

final class TaskFormData
{
	public string $description;

	public ?int $assigneeId = null;

	public ?\DateTimeImmutable $dueDate = null;
}
