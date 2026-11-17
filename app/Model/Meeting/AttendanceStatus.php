<?php

declare(strict_types=1);

namespace App\Model\Meeting;

enum AttendanceStatus: string
{
	case Unknown = 'unknown';
	case Present = 'present';
	case Excused = 'excused';
	case Absent = 'absent';

	public function label(): string
	{
		return match ($this) {
			self::Unknown => 'neurčeno',
			self::Present => 'přítomen',
			self::Excused => 'omluven',
			self::Absent => 'neomluven',
		};
	}

	public function tone(): string
	{
		return match ($this) {
			self::Unknown => 'secondary',
			self::Present => 'success',
			self::Excused => 'warning',
			self::Absent => 'danger',
		};
	}

	/**
	 * Stavy, které úřad přepíná na obrazovce průběhu.
	 *
	 * @return list<self>
	 */
	public static function choices(): array
	{
		return [self::Present, self::Excused, self::Absent];
	}
}
