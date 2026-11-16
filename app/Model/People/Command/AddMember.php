<?php

declare(strict_types=1);

namespace App\Model\People\Command;

use App\Model\People\MemberFunction;

final readonly class AddMember
{
	public function __construct(
		public int $personId,
		public MemberFunction $function,
		public \DateTimeImmutable $validFrom,
		public ?\DateTimeImmutable $validTo = null,
	) {}
}
