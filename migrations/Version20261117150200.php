<?php

declare(strict_types=1);

namespace App\Migrations;

use App\Core\Doctrine\Migration\SchemaMigration;
use App\Core\Doctrine\Migration\TableBuilder;
use Doctrine\DBAL\Schema\ForeignKeyConstraint\ReferentialAction;
use Doctrine\DBAL\Schema\Schema;

final class Version20261117150200 extends SchemaMigration
{
	public function getDescription(): string
	{
		return 'Usnesení a řady jejich čísel (resolution, numbering_sequence)';
	}

	public function up(Schema $schema): void
	{
		$this->createTable('numbering_sequence', static function (TableBuilder $table): void {
			$table->id()
				->foreignKey('municipality_id', 'municipality', onDelete: ReferentialAction::CASCADE)
				->foreignKey('body_id', 'body', onDelete: ReferentialAction::CASCADE)
				->string('scope_key', 50)
				->integer('last_value')
				->unique('body_id', 'scope_key');
		});

		$this->createTable('resolution', static function (TableBuilder $table): void {
			$table->id()
				->foreignKey('municipality_id', 'municipality', onDelete: ReferentialAction::CASCADE)
				->foreignKey('body_id', 'body', onDelete: ReferentialAction::CASCADE)
				->foreignKey('meeting_id', 'meeting', onDelete: ReferentialAction::CASCADE)
				->foreignKey('agenda_item_id', 'agenda_item', onDelete: ReferentialAction::CASCADE)
				->foreignKey('voting_round_id', 'voting_round', nullable: true, onDelete: ReferentialAction::SET_NULL)
				->string('number', 100)
				->integer('sequence')
				->string('scope_key', 50)
				->text('text')
				->string('status', 20)
				->datetime('adopted_at')
				->unique('body_id', 'scope_key', 'sequence')
				->unique('voting_round_id');
		});
	}

	public function down(Schema $schema): void
	{
		$this->dropTable('resolution');
		$this->dropTable('numbering_sequence');
	}
}
