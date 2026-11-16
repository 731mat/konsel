<?php

declare(strict_types=1);

namespace App\Domain\Meeting;

enum MeetingType: string
{
	case Regular = 'regular';
	case Extraordinary = 'extraordinary';
	case Constituent = 'constituent';

	public function label(): string
	{
		return match ($this) {
			self::Regular => 'Řádné',
			self::Extraordinary => 'Mimořádné',
			self::Constituent => 'Ustavující',
		};
	}
}
