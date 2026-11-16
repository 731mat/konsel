<?php

declare(strict_types=1);

namespace App\AdminModule\Project;

final class ProjectFormData
{
	public string $title;

	public ?string $description = null;

	public string $priority;

	public ?int $responsible = null;

	public ?int $estimatedCost = null;
}
