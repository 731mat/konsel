<?php

declare(strict_types=1);

namespace App\Migrations;

use App\Core\Doctrine\Migration\SchemaMigration;
use App\Core\Doctrine\Migration\TableBuilder;
use Doctrine\DBAL\Schema\ForeignKeyConstraint\ReferentialAction;
use Doctrine\DBAL\Schema\Schema;

final class Version20261116224500 extends SchemaMigration
{
	public function getDescription(): string
	{
		return 'Nastavení AI obce (ai_settings)';
	}

	public function up(Schema $schema): void
	{
		$this->createTable('ai_settings', static function (TableBuilder $table): void {
			$table->id()
				->foreignKey('municipality_id', 'municipality', onDelete: ReferentialAction::CASCADE)
				->text('api_key_encrypted', nullable: true)
				->string('api_key_hint', 20, nullable: true)
				->string('model', 100)
				->boolean('enabled')
				->datetime('verified_at', nullable: true)
				->timestamps()
				->unique('municipality_id');
		});
	}

	public function down(Schema $schema): void
	{
		$this->dropTable('ai_settings');
	}
}
