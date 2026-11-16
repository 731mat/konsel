<?php

declare(strict_types=1);

namespace App\Migrations;

use App\Core\Doctrine\Migration\SchemaMigration;
use App\Core\Doctrine\Migration\TableBuilder;
use Doctrine\DBAL\Schema\ForeignKeyConstraint\ReferentialAction;
use Doctrine\DBAL\Schema\Schema;

final class Version20261116233000 extends SchemaMigration
{
	public function getDescription(): string
	{
		return 'Šablony usnesení a programu zasedání';
	}

	public function up(Schema $schema): void
	{
		$this->createTable('resolution_template', static function (TableBuilder $table): void {
			$table->id()
				->foreignKey('municipality_id', 'municipality', onDelete: ReferentialAction::CASCADE)
				->string('title', 200)
				->string('category', 100)
				->text('text')
				->timestamps();
		});

		$this->createTable('meeting_template', static function (TableBuilder $table): void {
			$table->id()
				->foreignKey('municipality_id', 'municipality', onDelete: ReferentialAction::CASCADE)
				->string('name', 200)
				->string('meeting_type', 20)
				->text('note', nullable: true)
				->json('items')
				->timestamps();
		});
	}

	public function down(Schema $schema): void
	{
		$this->dropTable('meeting_template');
		$this->dropTable('resolution_template');
	}
}
