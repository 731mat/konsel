<?php

declare(strict_types=1);

namespace App\Migrations;

use App\Core\Doctrine\Migration\ColumnFactory as C;
use App\Core\Doctrine\Migration\SchemaMigration;
use App\Core\Doctrine\Migration\TableBuilder;
use Doctrine\DBAL\Schema\ForeignKeyConstraint\ReferentialAction;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\TableEditor;

final class Version20261118010000 extends SchemaMigration
{
	public function getDescription(): string
	{
		return 'AI asistent textů: pokyny a vzory obce (ai_settings, 1/2), záznam spotřeby (ai_usage_log)';
	}

	public function up(Schema $schema): void
	{
		$this->alterTable('ai_settings', static function (TableEditor $table): void {
			$table
				->addColumn(C::text('instructions', nullable: true))
				->addColumn(C::text('examples', nullable: true))
				->addColumn(C::boolean('use_recent_resolutions', default: true));
		});

		$this->createTable('ai_usage_log', static function (TableBuilder $table): void {
			$table->id()
				->foreignKey('municipality_id', 'municipality', onDelete: ReferentialAction::CASCADE)
				->foreignKey('user_id', 'app_user', nullable: true, onDelete: ReferentialAction::SET_NULL)
				->string('purpose', 50)
				->string('model', 100)
				->integer('prompt_tokens')
				->integer('completion_tokens')
				->datetime('created_at');
		});
	}

	public function down(Schema $schema): void
	{
		$this->dropTable('ai_usage_log');
		$this->alterTable('ai_settings', static function (TableEditor $table): void {
			$table
				->dropColumnByUnquotedName('use_recent_resolutions')
				->dropColumnByUnquotedName('examples')
				->dropColumnByUnquotedName('instructions');
		});
	}
}
