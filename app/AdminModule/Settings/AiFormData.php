<?php

declare(strict_types=1);

namespace App\AdminModule\Settings;

final class AiFormData
{
	public ?string $apiKey = null;

	public string $model;

	public bool $enabled;
}
