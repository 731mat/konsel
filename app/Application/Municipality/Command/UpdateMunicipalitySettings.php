<?php

declare(strict_types=1);

namespace App\Application\Municipality\Command;

final readonly class UpdateMunicipalitySettings
{
	public function __construct(
		public string $name,
		public string $officialName,
		public ?string $ico = null,
		public ?string $street = null,
		public ?string $city = null,
		public ?string $postalCode = null,
		public ?string $email = null,
		public ?string $phone = null,
		public ?string $website = null,
		public ?int $population = null,
	) {}
}
