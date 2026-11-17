<?php

declare(strict_types=1);

namespace App\Migrations;

use App\Core\Doctrine\Migration\ColumnFactory as C;
use App\Core\Doctrine\Migration\SchemaMigration;
use App\Core\Doctrine\Migration\TableBuilder;
use Doctrine\DBAL\Schema\ForeignKeyConstraint\ReferentialAction;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\TableEditor;

final class Version20261117230000 extends SchemaMigration
{
	public function getDescription(): string
	{
		return 'Podbody programu (agenda_item.parent_id) – o každém podbodu se hlasuje zvlášť';
	}

	public function up(Schema $schema): void
	{
		$this->alterTable('agenda_item', static function (TableEditor $table): void {
			[$index, $foreignKey] = TableBuilder::foreignKeyFor('agenda_item', 'parent_id', 'agenda_item', ReferentialAction::CASCADE);
			$table
				->addColumn(C::integer('parent_id', nullable: true))
				->addIndex($index)
				->addForeignKeyConstraint($foreignKey);
		});
	}

	public function down(Schema $schema): void
	{
		$this->alterTable('agenda_item', static function (TableEditor $table): void {
			[$index, $foreignKey] = TableBuilder::foreignKeyFor('agenda_item', 'parent_id', 'agenda_item', ReferentialAction::CASCADE);
			$foreignKeyName = $foreignKey->getObjectName()?->toString() ?? '';
			assert($foreignKeyName !== '');
			$table
				->dropForeignKeyConstraintByUnquotedName($foreignKeyName)
				->dropIndexByUnquotedName($index->getObjectName()->toString())
				->dropColumnByUnquotedName('parent_id');
		});
	}
}
