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

final class Version20261116234000 extends SchemaMigration
{
	public function getDescription(): string
	{
		return 'Přílohy komentářů: attachment.agenda_item_id nepovinné, nový comment_id';
	}

	public function up(Schema $schema): void
	{
		$this->alterTable('attachment', static function (TableEditor $table): void {
			[$index, $foreignKey] = TableBuilder::foreignKeyFor('attachment', 'comment_id', 'comment', ReferentialAction::CASCADE);
			$table
				->modifyColumnByUnquotedName('agenda_item_id', static function (ColumnEditor $column): void {
					$column->setNotNull(false);
				})
				->addColumn(C::integer('comment_id', nullable: true))
				->addIndex($index)
				->addForeignKeyConstraint($foreignKey);
		});
	}

	public function down(Schema $schema): void
	{
		$this->alterTable('attachment', static function (TableEditor $table): void {
			[$index, $foreignKey] = TableBuilder::foreignKeyFor('attachment', 'comment_id', 'comment', ReferentialAction::CASCADE);
			$foreignKeyName = $foreignKey->getObjectName()?->toString() ?? '';
			$indexName = $index->getObjectName()->toString();
			assert($foreignKeyName !== '');
			$table
				->dropForeignKeyConstraintByUnquotedName($foreignKeyName)
				->dropIndexByUnquotedName($indexName)
				->dropColumnByUnquotedName('comment_id')
				->modifyColumnByUnquotedName('agenda_item_id', static function (ColumnEditor $column): void {
					$column->setNotNull(true);
				});
		});
	}
}
