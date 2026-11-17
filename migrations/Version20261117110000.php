<?php

declare(strict_types=1);

namespace App\Migrations;

use App\Core\Doctrine\Migration\SchemaMigration;
use App\Core\Doctrine\Migration\TableBuilder;
use Doctrine\DBAL\Schema\ForeignKeyConstraint\ReferentialAction;
use Doctrine\DBAL\Schema\Schema;

final class Version20261117110000 extends SchemaMigration
{
	public function getDescription(): string
	{
		return 'Záznam odeslaných e-mailů (mail_log)';
	}

	public function up(Schema $schema): void
	{
		$this->createTable('mail_log', static function (TableBuilder $table): void {
			$table->id()
				->foreignKey('municipality_id', 'municipality', onDelete: ReferentialAction::CASCADE)
				->string('type', 30)
				->foreignKey('meeting_id', 'meeting', nullable: true, onDelete: ReferentialAction::CASCADE)
				->foreignKey('person_id', 'person', nullable: true, onDelete: ReferentialAction::SET_NULL)
				->string('recipient', 180)
				->string('subject', 255)
				->string('status', 20)
				->text('error', nullable: true)
				->datetime('sent_at')
				->index('meeting_id', 'sent_at');
		});
	}

	public function down(Schema $schema): void
	{
		$this->dropTable('mail_log');
	}
}
