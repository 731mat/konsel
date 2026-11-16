<?php

declare(strict_types=1);

namespace App\Model\Project;

enum ProjectPriority: string
{
	case Low = 'low';
	case Normal = 'normal';
	case High = 'high';
	case Urgent = 'urgent';

	public function label(): string
	{
		return match ($this) {
			self::Low => 'Nízká',
			self::Normal => 'Běžná',
			self::High => 'Vysoká',
			self::Urgent => 'Naléhavá',
		};
	}

	public function rank(): int
	{
		return match ($this) {
			self::Urgent => 0,
			self::High => 1,
			self::Normal => 2,
			self::Low => 3,
		};
	}
}
