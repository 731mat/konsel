<?php

declare(strict_types=1);

namespace App\Migrations;

use App\Core\Doctrine\Migration\SchemaMigration;
use Doctrine\DBAL\Schema\ColumnEditor;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\TableEditor;

final class Version20261117230200 extends SchemaMigration
{
	public function getDescription(): string
	{
		return 'Usnesení k podbodům – 2/2 odstranění dočasné výchozí hodnoty sub_number';
	}

	public function up(Schema $schema): void
	{
		$this->alterTable('resolution', static function (TableEditor $table): void {
			$table->modifyColumnByUnquotedName('sub_number', static function (ColumnEditor $column): void {
				$column->setDefaultValue(null);
			});
		});
	}

	public function down(Schema $schema): void
	{
		$this->alterTable('resolution', static function (TableEditor $table): void {
			$table->modifyColumnByUnquotedName('sub_number', static function (ColumnEditor $column): void {
				$column->setDefaultValue(0);
			});
		});
	}
}
