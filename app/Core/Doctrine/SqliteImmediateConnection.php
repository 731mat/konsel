<?php

declare(strict_types=1);

namespace App\Core\Doctrine;

use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;

/**
 * SQLite: transakce začínají jako BEGIN IMMEDIATE (ADR A3).
 *
 * Výchozí odložená transakce (BEGIN) nejdřív čte a zápisový zámek získává až při prvním zápisu. Když mezitím
 * zapsal někdo jiný, SQLite povýšení odmítne hned („database is locked“) a busy_timeout nepomůže. S BEGIN IMMEDIATE
 * se zápisové transakce seřadí za sebe už na začátku (čekají až busy_timeout) – nutné pro bezpečné přidělování
 * čísel usnesení a příjemnější pro souběh webu a konzole.
 */
final class SqliteImmediateConnection extends AbstractConnectionMiddleware
{
	public function beginTransaction(): void
	{
		$this->exec('BEGIN IMMEDIATE');
	}

	public function commit(): void
	{
		$this->exec('COMMIT');
	}

	public function rollBack(): void
	{
		$this->exec('ROLLBACK');
	}
}
