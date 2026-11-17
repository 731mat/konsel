<?php

declare(strict_types=1);

namespace App\Migrations;

use App\Core\Doctrine\Migration\SchemaMigration;
use App\Core\Doctrine\Migration\TableBuilder;
use Doctrine\DBAL\Schema\ForeignKeyConstraint\ReferentialAction;
use Doctrine\DBAL\Schema\Schema;

final class Version20261117220000 extends SchemaMigration
{
	public function getDescription(): string
	{
		return 'Vlastní šablony dokumentů obce v HTML/Latte (document_template)';
	}

	public function up(Schema $schema): void
	{
		$this->createTable('document_template', static function (TableBuilder $table): void {
			$table->id()
				->foreignKey('municipality_id', 'municipality', onDelete: ReferentialAction::CASCADE)
				->foreignKey('body_id', 'body', nullable: true, onDelete: ReferentialAction::CASCADE)
				->string('type', 30)
				->text('source')
				->foreignKey('updated_by_id', 'app_user', nullable: true, onDelete: ReferentialAction::SET_NULL)
				->timestamps()
				->unique('municipality_id', 'body_id', 'type');
		});
	}

	public function down(Schema $schema): void
	{
		$this->dropTable('document_template');
	}
}
