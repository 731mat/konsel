<?php

declare(strict_types=1);

namespace App\Migrations;

use App\Core\Doctrine\Migration\SchemaMigration;
use Doctrine\DBAL\Schema\ColumnEditor;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\TableEditor;

final class Version20261116160656 extends SchemaMigration
{
	public function getDescription(): string
	{
		return 'Obec: profil – 2/2 odstranění dočasné výchozí hodnoty official_name';
	}

	public function up(Schema $schema): void
	{
		$this->alterTable('municipality', static function (TableEditor $table): void {
			$table->modifyColumnByUnquotedName('official_name', static function (ColumnEditor $column): void {
				$column->setDefaultValue(null);
			});
		});
	}

	public function down(Schema $schema): void
	{
		$this->alterTable('municipality', static function (TableEditor $table): void {
			$table->modifyColumnByUnquotedName('official_name', static function (ColumnEditor $column): void {
				$column->setDefaultValue('');
			});
		});
	}
}
