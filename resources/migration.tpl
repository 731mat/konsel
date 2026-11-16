<?php

declare(strict_types=1);

namespace <namespace>;

use App\Core\Doctrine\Migration\SchemaMigration;
use App\Core\Doctrine\Migration\TableBuilder;
use Doctrine\DBAL\Schema\Schema;

/**
 * Píše se přes createTable()/alterTable()/dropTable() (SchemaMigration), ne raw SQL.
 * Výstup migrations:diff slouží jen jako předloha.
 */
final class <className> extends SchemaMigration
{
	public function getDescription(): string
	{
		return '';
	}

	public function up(Schema $schema): void
	{
<up>
	}

	public function down(Schema $schema): void
	{
<down>
	}
<override>}
