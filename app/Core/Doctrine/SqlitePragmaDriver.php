<?php

declare(strict_types=1);

namespace App\Core\Doctrine;

use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;

final class SqlitePragmaDriver extends AbstractDriverMiddleware
{
	private const array SqliteDrivers = ['pdo_sqlite', 'sqlite3'];

	public function connect(
		#[\SensitiveParameter]
		array $params,
	): Connection {
		$connection = parent::connect($params);

		if (! in_array($params['driver'] ?? null, self::SqliteDrivers, true)) {
			return $connection;
		}

		$connection->exec('PRAGMA foreign_keys = ON');
		$connection->exec('PRAGMA busy_timeout = 5000');

		if (($params['memory'] ?? false) !== true) {
			$connection->exec('PRAGMA journal_mode = WAL');
			$connection->exec('PRAGMA synchronous = NORMAL');
		}

		return new SqliteImmediateConnection($connection);
	}
}
