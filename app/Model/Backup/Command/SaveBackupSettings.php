<?php

declare(strict_types=1);

namespace App\Model\Backup\Command;

use App\Model\Backup\BackupContent;
use App\Model\Backup\BackupFrequency;

final readonly class SaveBackupSettings
{
	public function __construct(
		public bool $enabled,
		public BackupFrequency $frequency,
		public int $hour,
		public int $weekday,
		public int $monthDay,
		public BackupContent $content,
		public int $keep,
	) {}
}
