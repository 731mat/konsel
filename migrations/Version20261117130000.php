<?php

declare(strict_types=1);

namespace App\Migrations;

use App\Core\Doctrine\Migration\SchemaMigration;
use App\Core\Doctrine\Migration\TableBuilder;
use Doctrine\DBAL\Schema\ForeignKeyConstraint\ReferentialAction;
use Doctrine\DBAL\Schema\Schema;

final class Version20261117130000 extends SchemaMigration
{
	public function getDescription(): string
	{
		return 'Hlasování (voting_round, vote)';
	}

	public function up(Schema $schema): void
	{
		$this->createTable('voting_round', static function (TableBuilder $table): void {
			$table->id()
				->foreignKey('municipality_id', 'municipality', onDelete: ReferentialAction::CASCADE)
				->foreignKey('meeting_id', 'meeting', onDelete: ReferentialAction::CASCADE)
				->foreignKey('agenda_item_id', 'agenda_item', onDelete: ReferentialAction::CASCADE)
				->integer('sequence')
				->string('type', 20)
				->text('proposal_text', nullable: true)
				->foreignKey('candidate_id', 'person', nullable: true, onDelete: ReferentialAction::SET_NULL)
				->datetime('opened_at')
				->datetime('closed_at', nullable: true)
				->string('result', 20, nullable: true)
				->integer('for_count')
				->integer('against_count')
				->integer('abstain_count')
				->integer('not_voting_count')
				->integer('members_count')
				->unique('agenda_item_id', 'sequence');
		});

		$this->createTable('vote', static function (TableBuilder $table): void {
			$table->id()
				->foreignKey('voting_round_id', 'voting_round', onDelete: ReferentialAction::CASCADE)
				->foreignKey('person_id', 'person', onDelete: ReferentialAction::CASCADE)
				->string('choice', 20)
				->unique('voting_round_id', 'person_id');
		});
	}

	public function down(Schema $schema): void
	{
		$this->dropTable('vote');
		$this->dropTable('voting_round');
	}
}
