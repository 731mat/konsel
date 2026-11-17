<?php

declare(strict_types=1);

namespace App\Model\People;

/**
 * Od kdy se čísluje usnesení znovu od jedničky.
 */
enum NumberingScope: string
{
	case Year = 'year';
	case Term = 'term';
	case Meeting = 'meeting';

	public function label(): string
	{
		return match ($this) {
			self::Year => 'Každý rok od 1',
			self::Term => 'Celé volební období',
			self::Meeting => 'Každé zasedání od 1',
		};
	}
}
