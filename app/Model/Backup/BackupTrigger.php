<?php

declare(strict_types=1);

namespace App\Model\Backup;

enum BackupTrigger: string
{
	case Manual = 'manual';
	case Scheduled = 'scheduled';

	public function label(): string
	{
		return match ($this) {
			self::Manual => 'ručně',
			self::Scheduled => 'pravidelná',
		};
	}
}
