<?php

declare(strict_types=1);

namespace App\Migrations;

use App\Core\Doctrine\Migration\SchemaMigration;
use App\Core\Doctrine\Migration\TableBuilder;
use Doctrine\DBAL\Schema\ForeignKeyConstraint\ReferentialAction;
use Doctrine\DBAL\Schema\Schema;

final class Version20261118000000 extends SchemaMigration
{
	public function getDescription(): string
	{
		return 'Zvukové záznamy zasedání (recording)';
	}

	public function up(Schema $schema): void
	{
		$this->createTable('recording', static function (TableBuilder $table): void {
			$table->id()
				->foreignKey('municipality_id', 'municipality', onDelete: ReferentialAction::CASCADE)
				->foreignKey('meeting_id', 'meeting', onDelete: ReferentialAction::CASCADE)
				->json('file')
				->string('title')
				->foreignKey('uploaded_by_id', 'app_user', nullable: true, onDelete: ReferentialAction::SET_NULL)
				->timestamps();
		});
	}

	public function down(Schema $schema): void
	{
		$this->dropTable('recording');
	}
}
