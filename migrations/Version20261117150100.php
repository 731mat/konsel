<?php

declare(strict_types=1);

namespace App\Migrations;

use App\Core\Doctrine\Migration\SchemaMigration;
use Doctrine\DBAL\Schema\ColumnEditor;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\TableEditor;

final class Version20261117150100 extends SchemaMigration
{
	public function getDescription(): string
	{
		return 'Řada čísel usnesení orgánu – 2/2 (body.numbering_scope bez výchozí hodnoty)';
	}

	public function up(Schema $schema): void
	{
		$this->alterTable('body', static function (TableEditor $table): void {
			$table->modifyColumnByUnquotedName('numbering_scope', static function (ColumnEditor $column): void {
				$column->setDefaultValue(null);
			});
		});
	}

	public function down(Schema $schema): void
	{
		$this->alterTable('body', static function (TableEditor $table): void {
			$table->modifyColumnByUnquotedName('numbering_scope', static function (ColumnEditor $column): void {
				$column->setDefaultValue('year');
			});
		});
	}
}
