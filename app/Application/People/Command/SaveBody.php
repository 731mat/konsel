<?php

declare(strict_types=1);

namespace App\Application\People\Command;

use App\Domain\People\BodyType;

final readonly class SaveBody
{
	public function __construct(
		public BodyType $type,
		public string $name,
		public int $seatCount,
		public ?string $numberingMask = null,
	) {}
}
