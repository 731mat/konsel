<?php

declare(strict_types=1);

namespace App\Migrations;

use App\Core\Doctrine\Migration\ColumnFactory as C;
use App\Core\Doctrine\Migration\SchemaMigration;
use App\Core\Doctrine\Migration\TableBuilder;
use Doctrine\DBAL\Schema\ForeignKeyConstraint\ReferentialAction;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\TableEditor;

final class Version20261116235400 extends SchemaMigration
{
	public function getDescription(): string
	{
		return 'Soubory u úkolu (attachment.project_id)';
	}

	public function up(Schema $schema): void
	{
		$this->alterTable('attachment', static function (TableEditor $table): void {
			[$index, $foreignKey] = TableBuilder::foreignKeyFor('attachment', 'project_id', 'project', ReferentialAction::CASCADE);
			$table
				->addColumn(C::integer('project_id', nullable: true))
				->addIndex($index)
				->addForeignKeyConstraint($foreignKey);
		});
	}

	public function down(Schema $schema): void
	{
		$this->alterTable('attachment', static function (TableEditor $table): void {
			[$index, $foreignKey] = TableBuilder::foreignKeyFor('attachment', 'project_id', 'project', ReferentialAction::CASCADE);
			$foreignKeyName = $foreignKey->getObjectName()?->toString() ?? '';
			assert($foreignKeyName !== '');
			$table
				->dropForeignKeyConstraintByUnquotedName($foreignKeyName)
				->dropIndexByUnquotedName($index->getObjectName()->toString())
				->dropColumnByUnquotedName('project_id');
		});
	}
}
