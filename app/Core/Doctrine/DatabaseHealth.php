<?php

declare(strict_types=1);

namespace App\Core\Doctrine;

use Doctrine\DBAL\Connection;

/**
 * Kontrola dostupnosti databáze pro monitoring (/api/health).
 */
final readonly class DatabaseHealth
{
	public function __construct(
		private Connection $connection,
	) {}

	public function isAvailable(): bool
	{
		try {
			$this->connection->executeQuery('SELECT 1')->fetchOne();

			return true;
		} catch (\Throwable) {
			return false;
		}
	}
}
