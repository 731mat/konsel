<?php

declare(strict_types=1);

namespace App\Migrations;

use App\Core\Doctrine\Migration\SchemaMigration;
use App\Core\Doctrine\Migration\TableBuilder;
use Doctrine\DBAL\Schema\ForeignKeyConstraint\ReferentialAction;
use Doctrine\DBAL\Schema\Schema;

final class Version20261116235000 extends SchemaMigration
{
	public function getDescription(): string
	{
		return 'Úkolníček: úkoly (project) a historie fází';
	}

	public function up(Schema $schema): void
	{
		$this->createTable('project', static function (TableBuilder $table): void {
			$table->id()
				->foreignKey('municipality_id', 'municipality', onDelete: ReferentialAction::CASCADE)
				->string('title', 200)
				->text('description', nullable: true)
				->string('status', 20)
				->string('priority', 20)
				->foreignKey('responsible_id', 'person', nullable: true, onDelete: ReferentialAction::SET_NULL)
				->integer('estimated_cost', nullable: true)
				->boolean('public')
				->string('created_by_name', 200)
				->timestamps();
		});

		$this->createTable('project_status_change', static function (TableBuilder $table): void {
			$table->id()
				->foreignKey('project_id', 'project', onDelete: ReferentialAction::CASCADE)
				->string('from_status', 20)
				->string('to_status', 20)
				->datetime('changed_at')
				->string('changed_by_name', 200)
				->text('note', nullable: true);
		});
	}

	public function down(Schema $schema): void
	{
		$this->dropTable('project_status_change');
		$this->dropTable('project');
	}
}
