<?php

declare(strict_types=1);

namespace App\Migrations;

use App\Core\Doctrine\Migration\ColumnFactory as C;
use App\Core\Doctrine\Migration\SchemaMigration;
use App\Core\Doctrine\Migration\TableBuilder;
use Doctrine\DBAL\Schema\ForeignKeyConstraint\ReferentialAction;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\TableEditor;

final class Version20261116235100 extends SchemaMigration
{
	public function getDescription(): string
	{
		return 'Propojení bodu programu s úkolem (agenda_item.project_id)';
	}

	public function up(Schema $schema): void
	{
		$this->alterTable('agenda_item', static function (TableEditor $table): void {
			[$index, $foreignKey] = TableBuilder::foreignKeyFor('agenda_item', 'project_id', 'project', ReferentialAction::SET_NULL);
			$table
				->addColumn(C::integer('project_id', nullable: true))
				->addIndex($index)
				->addForeignKeyConstraint($foreignKey);
		});
	}

	public function down(Schema $schema): void
	{
		$this->alterTable('agenda_item', static function (TableEditor $table): void {
			[$index, $foreignKey] = TableBuilder::foreignKeyFor('agenda_item', 'project_id', 'project', ReferentialAction::SET_NULL);
			$foreignKeyName = $foreignKey->getObjectName()?->toString() ?? '';
			assert($foreignKeyName !== '');
			$table
				->dropForeignKeyConstraintByUnquotedName($foreignKeyName)
				->dropIndexByUnquotedName($index->getObjectName()->toString())
				->dropColumnByUnquotedName('project_id');
		});
	}
}
