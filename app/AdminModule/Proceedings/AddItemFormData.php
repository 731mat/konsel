<?php

declare(strict_types=1);

namespace App\AdminModule\Proceedings;

final class AddItemFormData
{
	public string $title;

	public ?string $proposedResolution = null;

	public ?int $position = null;
}
