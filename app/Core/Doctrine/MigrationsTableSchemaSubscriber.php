<?php

declare(strict_types=1);

namespace App\Core\Doctrine;

use Doctrine\Common\EventSubscriber;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;
use Doctrine\ORM\Tools\ToolEvents;

/**
 * Přidá tabulku doctrine_migrations do schématu generovaného z ORM mapování.
 * Díky tomu ji orm:validate-schema / migrations:diff / SchemaValidator nechtějí smazat
 * a zároveň ji vidí metadata storage migrací (nesmí být skrytá filtrem).
 *
 * Definice odpovídá Doctrine\Migrations\Metadata\Storage\TableMetadataStorage::getExpectedTable().
 * DBAL 4.6 nemá nedeprekované API pro úpravu schématu v této události – výjimka je jen v tomto souboru
 * (phpstan.neon ignoreErrors). Až ORM přidá náhradu, přepsat.
 */
final class MigrationsTableSchemaSubscriber implements EventSubscriber
{
	public const string Table = 'doctrine_migrations';

	public function getSubscribedEvents(): array
	{
		return [ToolEvents::postGenerateSchema];
	}

	public function postGenerateSchema(GenerateSchemaEventArgs $args): void
	{
		$schema = $args->getSchema();
		if ($schema->hasTable(self::Table)) {
			return;
		}

		$table = $schema->createTable(self::Table);
		$table->addColumn('version', Types::STRING, [
			'length' => 191,
		]);
		$table->addColumn('executed_at', Types::DATETIME_MUTABLE, [
			'notnull' => false,
		]);
		$table->addColumn('execution_time', Types::INTEGER, [
			'notnull' => false,
		]);
		$table->setPrimaryKey(['version']);
	}
}
