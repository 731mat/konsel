<?php

declare(strict_types=1);

namespace App\Model\Backup;

/**
 * Konzistentní kopie databáze za běhu (SQLite: VACUUM INTO, MySQL: mysqldump v T42).
 */
interface DatabaseDumper
{
	/**
	 * @return string přípona souboru v záloze (např. „sqlite“)
	 * @throws BackupException
	 */
	public function dump(string $targetPath): string;
}
