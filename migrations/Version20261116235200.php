<?php

declare(strict_types=1);

namespace App\Migrations;

use App\Core\Doctrine\Migration\ColumnFactory as C;
use App\Core\Doctrine\Migration\SchemaMigration;
use App\Core\Doctrine\Migration\TableBuilder;
use Doctrine\DBAL\Schema\ColumnEditor;
use Doctrine\DBAL\Schema\ForeignKeyConstraint\ReferentialAction;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\TableEditor;

final class Version20261116235200 extends SchemaMigration
{
	public function getDescription(): string
	{
		return 'Komentáře k úkolům a viditelnost pro občany – 1/2 (sloupec s výchozí hodnotou)';
	}

	public function up(Schema $schema): void
	{
		$this->alterTable('comment', static function (TableEditor $table): void {
			[$index, $foreignKey] = TableBuilder::foreignKeyFor('comment', 'project_id', 'project', ReferentialAction::CASCADE);
			$table
				->modifyColumnByUnquotedName('agenda_item_id', static function (ColumnEditor $column): void {
					$column->setNotNull(false);
				})
				->addColumn(C::integer('project_id', nullable: true))
				->addColumn(C::boolean('public_for_citizens', default: false))
				->addIndex($index)
				->addForeignKeyConstraint($foreignKey);
		});
	}

	public function down(Schema $schema): void
	{
		$this->alterTable('comment', static function (TableEditor $table): void {
			[$index, $foreignKey] = TableBuilder::foreignKeyFor('comment', 'project_id', 'project', ReferentialAction::CASCADE);
			$foreignKeyName = $foreignKey->getObjectName()?->toString() ?? '';
			assert($foreignKeyName !== '');
			$table
				->dropForeignKeyConstraintByUnquotedName($foreignKeyName)
				->dropIndexByUnquotedName($index->getObjectName()->toString())
				->dropColumnByUnquotedName('project_id')
				->dropColumnByUnquotedName('public_for_citizens')
				->modifyColumnByUnquotedName('agenda_item_id', static function (ColumnEditor $column): void {
					$column->setNotNull(true);
				});
		});
	}
}
