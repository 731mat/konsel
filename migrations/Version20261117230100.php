<?php

declare(strict_types=1);

namespace App\Migrations;

use App\Core\Doctrine\Migration\ColumnFactory as C;
use App\Core\Doctrine\Migration\SchemaMigration;
use App\Core\Doctrine\Migration\TableBuilder;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\TableEditor;

final class Version20261117230100 extends SchemaMigration
{
	public function getDescription(): string
	{
		return 'Usnesení k podbodům: číslo podbodu (1/2, výchozí 0) a unikátnost řady včetně podbodu';
	}

	public function up(Schema $schema): void
	{
		$this->alterTable('resolution', static function (TableEditor $table): void {
			$table
				->addColumn(C::integer('sub_number', default: 0))
				->dropIndexByUnquotedName(TableBuilder::uniqueIndexFor('resolution', 'body_id', 'scope_key', 'sequence')->getObjectName()->toString())
				->addIndex(TableBuilder::uniqueIndexFor('resolution', 'body_id', 'scope_key', 'sequence', 'sub_number'));
		});
	}

	public function down(Schema $schema): void
	{
		$this->alterTable('resolution', static function (TableEditor $table): void {
			$table
				->dropIndexByUnquotedName(TableBuilder::uniqueIndexFor('resolution', 'body_id', 'scope_key', 'sequence', 'sub_number')->getObjectName()->toString())
				->addIndex(TableBuilder::uniqueIndexFor('resolution', 'body_id', 'scope_key', 'sequence'))
				->dropColumnByUnquotedName('sub_number');
		});
	}
}
