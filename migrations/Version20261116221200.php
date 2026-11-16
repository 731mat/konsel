<?php

declare(strict_types=1);

namespace App\Migrations;

use App\Core\Doctrine\Migration\SchemaMigration;
use App\Core\Doctrine\Migration\TableBuilder;
use Doctrine\DBAL\Schema\ForeignKeyConstraint\ReferentialAction;
use Doctrine\DBAL\Schema\Schema;

final class Version20261116221200 extends SchemaMigration
{
	public function getDescription(): string
	{
		return 'Body programu (agenda_item)';
	}

	public function up(Schema $schema): void
	{
		$this->createTable('agenda_item', static function (TableBuilder $table): void {
			$table->id()
				->foreignKey('municipality_id', 'municipality', onDelete: ReferentialAction::CASCADE)
				->foreignKey('meeting_id', 'meeting', onDelete: ReferentialAction::CASCADE)
				->integer('position')
				->string('title', 300)
				->text('description', nullable: true)
				->text('proposed_resolution', nullable: true)
				->foreignKey('rapporteur_id', 'person', nullable: true)
				->timestamps();
		});
	}

	public function down(Schema $schema): void
	{
		$this->dropTable('agenda_item');
	}
}
