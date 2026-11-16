<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Migration;

use Doctrine\DBAL\Schema\TableEditor;
use Doctrine\Migrations\AbstractMigration;

/**
 * Předek migrací Konšela. SQL generuje platforma za běhu, migrace tedy poběží na SQLite i MySQL.
 * Raw SQL (addSql) jen výjimečně a s větvením podle platformy.
 */
abstract class SchemaMigration extends AbstractMigration
{
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
}
