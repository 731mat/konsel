<?php

declare(strict_types=1);

namespace App\Application\People\Command;

use App\Domain\People\MemberFunction;

final readonly class AddMember
{
	public function __construct(
		public int $personId,
		public MemberFunction $function,
		public \DateTimeImmutable $validFrom,
		public ?\DateTimeImmutable $validTo = null,
	) {}
}
