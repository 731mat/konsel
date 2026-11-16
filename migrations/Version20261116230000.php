<?php

declare(strict_types=1);

namespace App\Migrations;

use App\Core\Doctrine\Migration\SchemaMigration;
use App\Core\Doctrine\Migration\TableBuilder;
use Doctrine\DBAL\Schema\ForeignKeyConstraint\ReferentialAction;
use Doctrine\DBAL\Schema\Schema;

final class Version20261116230000 extends SchemaMigration
{
	public function getDescription(): string
	{
		return 'Přílohy k bodům programu (attachment)';
	}

	public function up(Schema $schema): void
	{
		$this->createTable('attachment', static function (TableBuilder $table): void {
			$table->id()
				->foreignKey('municipality_id', 'municipality', onDelete: ReferentialAction::CASCADE)
				->foreignKey('agenda_item_id', 'agenda_item', onDelete: ReferentialAction::CASCADE)
				->json('file')
				->string('title')
				->foreignKey('uploaded_by_id', 'app_user', nullable: true, onDelete: ReferentialAction::SET_NULL)
				->boolean('public_for_members')
				->timestamps();
		});
	}

	public function down(Schema $schema): void
	{
		$this->dropTable('attachment');
	}
}
