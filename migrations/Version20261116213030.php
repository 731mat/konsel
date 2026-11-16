<?php

declare(strict_types=1);

namespace App\Migrations;

use App\Infrastructure\Persistence\Migration\SchemaMigration;
use App\Infrastructure\Persistence\Migration\TableBuilder;
use Doctrine\DBAL\Schema\ForeignKeyConstraint\ReferentialAction;
use Doctrine\DBAL\Schema\Schema;

final class Version20261116213030 extends SchemaMigration
{
	public function getDescription(): string
	{
		return 'Zasedání (meeting)';
	}

	public function up(Schema $schema): void
	{
		$this->createTable('meeting', static function (TableBuilder $table): void {
			$table->id()
				->foreignKey('municipality_id', 'municipality', onDelete: ReferentialAction::CASCADE)
				->foreignKey('body_id', 'body')
				->integer('number')
				->string('type', 20)
				->datetime('scheduled_at')
				->string('place', 200)
				->string('status', 20)
				->foreignKey('chairperson_id', 'person', nullable: true)
				->foreignKey('recorder_id', 'person', nullable: true)
				->foreignKey('first_verifier_id', 'person', nullable: true)
				->foreignKey('second_verifier_id', 'person', nullable: true)
				->datetime('shared_at', nullable: true)
				->datetime('started_at', nullable: true)
				->datetime('ended_at', nullable: true)
				->timestamps()
				->unique('body_id', 'number')
				->index('scheduled_at');
		});
	}

	public function down(Schema $schema): void
	{
		$this->dropTable('meeting');
	}
}
