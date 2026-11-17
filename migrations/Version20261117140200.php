<?php

declare(strict_types=1);

namespace App\Migrations;

use App\Core\Doctrine\Migration\SchemaMigration;
use Doctrine\DBAL\Schema\ColumnEditor;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\TableEditor;

final class Version20261117140200 extends SchemaMigration
{
	public function getDescription(): string
	{
		return 'Body doplněné na zasedání – 2/2 (agenda_item.added_during_meeting bez výchozí hodnoty)';
	}

	public function up(Schema $schema): void
	{
		$this->alterTable('agenda_item', static function (TableEditor $table): void {
			$table->modifyColumnByUnquotedName('added_during_meeting', static function (ColumnEditor $column): void {
				$column->setDefaultValue(null);
			});
		});
	}

	public function down(Schema $schema): void
	{
		$this->alterTable('agenda_item', static function (TableEditor $table): void {
			$table->modifyColumnByUnquotedName('added_during_meeting', static function (ColumnEditor $column): void {
				$column->setDefaultValue(false);
			});
		});
	}
}
