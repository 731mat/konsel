<?php

declare(strict_types=1);

namespace App\Model\Backup;

enum BackupFrequency: string
{
	case Daily = 'daily';
	case Weekly = 'weekly';
	case Monthly = 'monthly';

	public function label(): string
	{
		return match ($this) {
			self::Daily => 'každý den',
			self::Weekly => 'jednou týdně',
			self::Monthly => 'jednou měsíčně',
		};
	}
}
