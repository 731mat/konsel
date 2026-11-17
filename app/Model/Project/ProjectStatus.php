<?php

declare(strict_types=1);

namespace App\Model\Project;

/**
 * Fáze plánu. Hlavní cesta: námět → příprava → projekt → schváleno → realizace → hotovo.
 */
enum ProjectStatus: string
{
	case Submitted = 'submitted';
	case Idea = 'idea';
	case Preparation = 'preparation';
	case Design = 'design';
	case Approved = 'approved';
	case InProgress = 'in_progress';
	case Done = 'done';
	case Postponed = 'postponed';
	case Rejected = 'rejected';

	public function label(): string
	{
		return match ($this) {
			self::Submitted => 'Nový podnět',
			self::Idea => 'Námět',
			self::Preparation => 'V přípravě',
			self::Design => 'Projekt / studie',
			self::Approved => 'Schváleno',
			self::InProgress => 'Realizace',
			self::Done => 'Hotovo',
			self::Postponed => 'Odloženo',
			self::Rejected => 'Zamítnuto',
		};
	}

	/**
	 * Barva štítku (Bootstrap).
	 */
	public function tone(): string
	{
		return match ($this) {
			self::Submitted => 'warning',
			self::Idea => 'secondary',
			self::Preparation, self::Design => 'info',
			self::Approved, self::InProgress => 'primary',
			self::Done => 'success',
			self::Postponed, self::Rejected => 'dark',
		};
	}

	/**
	 * Pořadí na hlavní cestě (pro ukazatel postupu); null = mimo cestu (odloženo, zamítnuto, nový podnět).
	 */
	public function step(): ?int
	{
		return match ($this) {
			self::Idea => 1,
			self::Preparation => 2,
			self::Design => 3,
			self::Approved => 4,
			self::InProgress => 5,
			self::Done => 6,
			default => null,
		};
	}

	public function isActive(): bool
	{
		return !in_array($this, [self::Done, self::Rejected, self::Postponed], true);
	}

	/**
	 * @return list<self> fáze hlavní cesty v pořadí
	 */
	public static function path(): array
	{
		return [self::Idea, self::Preparation, self::Design, self::Approved, self::InProgress, self::Done];
	}
}
