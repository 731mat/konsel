<?php

declare(strict_types=1);

namespace App\Migrations;

use App\Core\Doctrine\Migration\SchemaMigration;
use App\Core\Doctrine\Migration\TableBuilder;
use Doctrine\DBAL\Schema\ForeignKeyConstraint\ReferentialAction;
use Doctrine\DBAL\Schema\Schema;

final class Version20261117180000 extends SchemaMigration
{
	public function getDescription(): string
	{
		return 'Archiv dokumentů zasedání (archived_document): uložené verze PDF a podepsané originály';
	}

	public function up(Schema $schema): void
	{
		$this->createTable('archived_document', static function (TableBuilder $table): void {
			$table->id()
				->foreignKey('municipality_id', 'municipality', onDelete: ReferentialAction::CASCADE)
				->foreignKey('meeting_id', 'meeting', onDelete: ReferentialAction::CASCADE)
				->string('type', 20)
				->string('origin', 20)
				->json('file')
				->string('note', nullable: true)
				->foreignKey('created_by_id', 'app_user', nullable: true, onDelete: ReferentialAction::SET_NULL)
				->string('meeting_status', 20)
				->timestamps()
				->namedIndex('archived_document_meeting_idx', 'meeting_id', 'type');
		});
	}

	public function down(Schema $schema): void
	{
		$this->dropTable('archived_document');
	}
}
