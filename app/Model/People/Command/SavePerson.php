<?php

declare(strict_types=1);

namespace App\Model\People\Command;

final readonly class SavePerson
{
	public function __construct(
		public string $firstName,
		public string $lastName,
		public ?string $titleBefore = null,
		public ?string $titleAfter = null,
		public ?string $email = null,
		public ?string $phone = null,
	) {}
}
