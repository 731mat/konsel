<?php

declare(strict_types=1);

namespace App\Core\Doctrine;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Middleware;

/**
 * Nastaví PRAGMA pro SQLite při každém připojení (FK, WAL, busy timeout).
 * Pro jiné platformy nedělá nic.
 */
final class SqlitePragmaMiddleware implements Middleware
{
	public function wrap(Driver $driver): Driver
	{
		return new SqlitePragmaDriver($driver);
	}
}
