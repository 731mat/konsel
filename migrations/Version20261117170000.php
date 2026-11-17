<?php

declare(strict_types=1);

namespace App\Migrations;

use App\Core\Doctrine\Migration\ColumnFactory as C;
use App\Core\Doctrine\Migration\SchemaMigration;
use App\Core\Doctrine\Migration\TableBuilder;
use Doctrine\DBAL\Schema\ForeignKeyConstraint\ReferentialAction;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\TableEditor;

final class Version20261117170000 extends SchemaMigration
{
	public function getDescription(): string
	{
		return 'Souběžná práce: zámky bodů (agenda_item_lock), verze zasedání a bodu (výchozí 1 – vyžaduje ji Doctrine #[Version])';
	}

	public function up(Schema $schema): void
	{
		$this->createTable('agenda_item_lock', static function (TableBuilder $table): void {
			$table->id()
				->foreignKey('municipality_id', 'municipality', onDelete: ReferentialAction::CASCADE)
				->foreignKey('agenda_item_id', 'agenda_item', onDelete: ReferentialAction::CASCADE)
				->foreignKey('user_id', 'app_user', onDelete: ReferentialAction::CASCADE)
				->datetime('acquired_at')
				->datetime('expires_at')
				->unique('agenda_item_id');
		});

		$this->alterTable('meeting', static function (TableEditor $table): void {
			$table->addColumn(C::integer('version', default: 1));
		});

		$this->alterTable('agenda_item', static function (TableEditor $table): void {
			$table->addColumn(C::integer('version', default: 1));
		});
	}

	public function down(Schema $schema): void
	{
		$this->alterTable('agenda_item', static function (TableEditor $table): void {
			$table->dropColumnByUnquotedName('version');
		});
		$this->alterTable('meeting', static function (TableEditor $table): void {
			$table->dropColumnByUnquotedName('version');
		});
		$this->dropTable('agenda_item_lock');
	}
}
