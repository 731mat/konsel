<?php

declare(strict_types=1);

namespace App\Migrations;

use App\Core\Doctrine\Migration\SchemaMigration;
use App\Core\Doctrine\Migration\TableBuilder;
use Doctrine\DBAL\Schema\ForeignKeyConstraint\ReferentialAction;
use Doctrine\DBAL\Schema\Schema;

final class Version20261118020000 extends SchemaMigration
{
	public function getDescription(): string
	{
		return 'Historie změn – kdo, kdy, co a z čeho na co (audit_entry)';
	}

	public function up(Schema $schema): void
	{
		$this->createTable('audit_entry', static function (TableBuilder $table): void {
			$table->id()
				->foreignKey('municipality_id', 'municipality', onDelete: ReferentialAction::CASCADE)
				->integer('meeting_id', nullable: true)
				->string('entity_type', 40)
				->integer('entity_id')
				->integer('related_id', nullable: true)
				->string('entity_label')
				->string('action', 20)
				->json('changes', nullable: true)
				->foreignKey('actor_user_id', 'app_user', nullable: true, onDelete: ReferentialAction::SET_NULL)
				->string('actor_name', 150)
				->string('ip', 45, nullable: true)
				->datetime('created_at')
				->namedIndex('audit_entry_municipality_idx', 'municipality_id', 'created_at')
				->namedIndex('audit_entry_meeting_idx', 'meeting_id')
				->namedIndex('audit_entry_entity_idx', 'entity_type', 'entity_id')
				->namedIndex('audit_entry_related_idx', 'related_id');
		});
	}

	public function down(Schema $schema): void
	{
		$this->dropTable('audit_entry');
	}
}
