<?php

declare(strict_types=1);

namespace App\Migrations;

use App\Core\Doctrine\Migration\SchemaMigration;
use App\Core\Doctrine\Migration\TableBuilder;
use Doctrine\DBAL\Schema\ForeignKeyConstraint\ReferentialAction;
use Doctrine\DBAL\Schema\Schema;

final class Version20261116231000 extends SchemaMigration
{
	public function getDescription(): string
	{
		return 'Komentáře k bodům programu (comment)';
	}

	public function up(Schema $schema): void
	{
		$this->createTable('comment', static function (TableBuilder $table): void {
			$table->id()
				->foreignKey('municipality_id', 'municipality', onDelete: ReferentialAction::CASCADE)
				->foreignKey('agenda_item_id', 'agenda_item', onDelete: ReferentialAction::CASCADE)
				->foreignKey('parent_id', 'comment', nullable: true, onDelete: ReferentialAction::CASCADE)
				->foreignKey('author_user_id', 'app_user', nullable: true, onDelete: ReferentialAction::SET_NULL)
				->foreignKey('author_person_id', 'person', nullable: true, onDelete: ReferentialAction::SET_NULL)
				->string('author_name', 200)
				->text('body')
				->datetime('deleted_at', nullable: true)
				->timestamps()
				->index('agenda_item_id', 'created_at');
		});
	}

	public function down(Schema $schema): void
	{
		$this->dropTable('comment');
	}
}
