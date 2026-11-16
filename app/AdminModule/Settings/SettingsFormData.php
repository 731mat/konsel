<?php

declare(strict_types=1);

namespace App\AdminModule\Settings;

use Nette\Http\FileUpload;

final class SettingsFormData
{
	public string $name;

	public string $officialName;

	public ?string $ico;

	public ?string $street;

	public ?string $city;

	public ?string $postalCode;

	public ?string $email;

	public ?string $phone;

	public ?string $website;

	public ?int $population;

	public ?FileUpload $logo;

	public bool $removeLogo = false;
}
