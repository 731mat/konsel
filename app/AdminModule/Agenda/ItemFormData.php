<?php

declare(strict_types=1);

namespace App\AdminModule\Agenda;

final class ItemFormData
{
	public string $title;

	public ?int $rapporteur = null;

	public ?int $project = null;

	public ?int $parent = null;

	public ?string $description = null;

	public ?string $proposedResolution = null;
}
