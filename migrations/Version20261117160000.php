<?php

declare(strict_types=1);

namespace App\Migrations;

use App\Core\Doctrine\Migration\SchemaMigration;
use App\Core\Doctrine\Migration\TableBuilder;
use Doctrine\DBAL\Schema\ForeignKeyConstraint\ReferentialAction;
use Doctrine\DBAL\Schema\Schema;

final class Version20261117160000 extends SchemaMigration
{
	public function getDescription(): string
	{
		return 'Úkoly z usnesení (resolution_task)';
	}

	public function up(Schema $schema): void
	{
		$this->createTable('resolution_task', static function (TableBuilder $table): void {
			$table->id()
				->foreignKey('municipality_id', 'municipality', onDelete: ReferentialAction::CASCADE)
				->foreignKey('resolution_id', 'resolution', onDelete: ReferentialAction::CASCADE)
				->string('description', 500)
				->foreignKey('assignee_id', 'person', nullable: true, onDelete: ReferentialAction::SET_NULL)
				->date('due_date', nullable: true)
				->string('status', 20)
				->text('note', nullable: true)
				->datetime('completed_at', nullable: true)
				->datetime('created_at')
				->index('status', 'due_date');
		});
	}

	public function down(Schema $schema): void
	{
		$this->dropTable('resolution_task');
	}
}
