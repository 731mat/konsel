<?php

declare(strict_types=1);

namespace App\Migrations;

use App\Core\Doctrine\Migration\SchemaMigration;
use Doctrine\DBAL\Schema\ColumnEditor;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\TableEditor;

final class Version20261118010100 extends SchemaMigration
{
	public function getDescription(): string
	{
		return 'AI asistent textů – 2/2 odstranění dočasné výchozí hodnoty use_recent_resolutions';
	}

	public function up(Schema $schema): void
	{
		$this->alterTable('ai_settings', static function (TableEditor $table): void {
			$table->modifyColumnByUnquotedName('use_recent_resolutions', static function (ColumnEditor $column): void {
				$column->setDefaultValue(null);
			});
		});
	}

	public function down(Schema $schema): void
	{
		$this->alterTable('ai_settings', static function (TableEditor $table): void {
			$table->modifyColumnByUnquotedName('use_recent_resolutions', static function (ColumnEditor $column): void {
				$column->setDefaultValue(true);
			});
		});
	}
}
