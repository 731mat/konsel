<?php

declare(strict_types=1);

namespace App\Model\Backup;

interface BackupSettingsRepository
{
	/**
	 * Nastavení instalace (vznikne s výchozími hodnotami, pokud ještě není).
	 */
	public function get(): BackupSettings;
}
