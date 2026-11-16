<?php

declare(strict_types=1);

namespace App\Migrations;

use App\Core\Doctrine\Migration\SchemaMigration;
use Doctrine\DBAL\Schema\ColumnEditor;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\TableEditor;

final class Version20261116235300 extends SchemaMigration
{
	public function getDescription(): string
	{
		return 'Komentáře k úkolům – 2/2 (odebrání výchozí hodnoty public_for_citizens)';
	}

	public function up(Schema $schema): void
	{
		$this->alterTable('comment', static function (TableEditor $table): void {
			$table->modifyColumnByUnquotedName('public_for_citizens', static function (ColumnEditor $column): void {
				$column->setDefaultValue(null);
			});
		});
	}

	public function down(Schema $schema): void
	{
		$this->alterTable('comment', static function (TableEditor $table): void {
			$table->modifyColumnByUnquotedName('public_for_citizens', static function (ColumnEditor $column): void {
				$column->setDefaultValue(false);
			});
		});
	}
}
