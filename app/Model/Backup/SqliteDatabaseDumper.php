<?php

declare(strict_types=1);

namespace App\Model\Backup;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\SQLitePlatform;

/**
 * SQLite: VACUUM INTO vytvoří konzistentní a zhuštěnou kopii i během provozu (WAL).
 */
final readonly class SqliteDatabaseDumper implements DatabaseDumper
{
	public function __construct(
		private Connection $connection,
	) {}

	public function dump(string $targetPath): string
	{
		if (!$this->connection->getDatabasePlatform() instanceof SQLitePlatform) {
			throw new BackupException('Záloha databáze je zatím jen pro SQLite (MySQL doplní přechod na MySQL).');
		}

		try {
			$this->connection->executeStatement('VACUUM INTO ' . $this->connection->quote($targetPath));
		} catch (\Throwable $e) {
			throw new BackupException('Kopii databáze se nepodařilo vytvořit: ' . $e->getMessage(), 0, $e);
		}

		return 'sqlite';
	}
}
