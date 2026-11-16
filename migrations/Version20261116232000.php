<?php

declare(strict_types=1);

namespace App\Migrations;

use App\Core\Doctrine\Migration\SchemaMigration;
use App\Core\Doctrine\Migration\TableBuilder;
use Doctrine\DBAL\Schema\ForeignKeyConstraint\ReferentialAction;
use Doctrine\DBAL\Schema\Schema;

final class Version20261116232000 extends SchemaMigration
{
	public function getDescription(): string
	{
		return 'Osobní odkazy zastupitelů k zasedání (meeting_access_token)';
	}

	public function up(Schema $schema): void
	{
		$this->createTable('meeting_access_token', static function (TableBuilder $table): void {
			$table->id()
				->foreignKey('municipality_id', 'municipality', onDelete: ReferentialAction::CASCADE)
				->foreignKey('meeting_id', 'meeting', onDelete: ReferentialAction::CASCADE)
				->foreignKey('person_id', 'person', onDelete: ReferentialAction::CASCADE)
				->string('token_hash', 64)
				->datetime('created_at')
				->datetime('expires_at')
				->datetime('revoked_at', nullable: true)
				->datetime('last_used_at', nullable: true)
				->integer('use_count')
				->unique('token_hash');
		});
	}

	public function down(Schema $schema): void
	{
		$this->dropTable('meeting_access_token');
	}
}
