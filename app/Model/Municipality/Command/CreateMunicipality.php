<?php

declare(strict_types=1);

namespace App\Model\Municipality\Command;

final readonly class CreateMunicipality
{
	public function __construct(
		public string $name,
		public ?string $ico = null,
		public ?string $slug = null,
	) {}
}
