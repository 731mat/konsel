<?php

declare(strict_types=1);

namespace App\Model\People\Command;

use App\Model\People\BodyType;
use App\Model\People\NumberingScope;

final readonly class SaveBody
{
	public function __construct(
		public BodyType $type,
		public string $name,
		public int $seatCount,
		public ?string $numberingMask = null,
		public ?NumberingScope $numberingScope = null,
	) {}
}
