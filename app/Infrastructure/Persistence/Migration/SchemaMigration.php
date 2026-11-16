<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Migration;

use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\TableEditor;
use Doctrine\Migrations\AbstractMigration;

/**
 * Předek migrací Konšela. SQL generuje platforma za běhu, migrace tedy poběží na SQLite i MySQL.
 * Raw SQL (addSql) jen výjimečně a s větvením podle platformy.
 *
 * SQLite: ALTER TABLE se často provádí přestavbou tabulky (DROP + CREATE). Se zapnutými cizími klíči
 * by DROP spustil ON DELETE CASCADE a smazal data v podřízených tabulkách. Proto se na SQLite
 * cizí klíče během migrace vypnou (PRAGMA jde měnit jen mimo transakci → transakci řídíme sami),
 * na konci se ověří PRAGMA foreign_key_check a klíče se zase zapnou.
 */
abstract class SchemaMigration extends AbstractMigration
{
	public function isTransactional(): bool
	{
		return !$this->isSqlite();
	}

	public function preUp(Schema $schema): void
	{
		$this->beginSqliteMigration();
	}

	public function postUp(Schema $schema): void
	{
		$this->finishSqliteMigration();
	}

	public function preDown(Schema $schema): void
	{
		$this->beginSqliteMigration();
	}

	public function postDown(Schema $schema): void
	{
		$this->finishSqliteMigration();
	}

	/**
	 * @param non-empty-string $name
	 * @param callable(TableBuilder): void $definition
	 */
	protected function createTable(string $name, callable $definition): void
	{
		$builder = new TableBuilder($name);
		$definition($builder);

		foreach ($this->platform->getCreateTableSQL($builder->build()) as $sql) {
			$this->addSql($sql);
		}
	}

	protected function dropTable(string $name): void
	{
		$this->addSql($this->platform->getDropTableSQL($this->platform->quoteSingleIdentifier($name)));
	}

	/**
	 * Úprava existující tabulky (přidání sloupce, indexu…). Tabulka musí existovat už před touto migrací.
	 *
	 * @param non-empty-string $name
	 * @param callable(TableEditor): void $modification
	 */
	protected function alterTable(string $name, callable $modification): void
	{
		$schemaManager = $this->connection->createSchemaManager();
		$current = $schemaManager->introspectTableByUnquotedName($name);

		$editor = $current->edit();
		$modification($editor);

		$diff = $schemaManager->createComparator()->compareTables($current, $editor->create());
		foreach ($this->platform->getAlterTableSQL($diff) as $sql) {
			$this->addSql($sql);
		}
	}

	private function isSqlite(): bool
	{
		return $this->platform instanceof SQLitePlatform;
	}

	private function beginSqliteMigration(): void
	{
		if (!$this->isSqlite()) {
			return;
		}

		$this->connection->executeStatement('PRAGMA foreign_keys = OFF');
		$this->connection->beginTransaction();
	}

	private function finishSqliteMigration(): void
	{
		if (!$this->isSqlite()) {
			return;
		}

		$violations = $this->connection->fetchAllAssociative('PRAGMA foreign_key_check');
		if ($violations !== []) {
			$this->connection->rollBack();
			$this->connection->executeStatement('PRAGMA foreign_keys = ON');

			throw new \RuntimeException('Migration would break foreign keys: ' . json_encode($violations));
		}

		$this->connection->commit();
		$this->connection->executeStatement('PRAGMA foreign_keys = ON');
	}
}
