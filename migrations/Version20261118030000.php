<?php

declare(strict_types=1);

namespace App\Migrations;

use App\Core\Doctrine\Migration\SchemaMigration;
use App\Core\Doctrine\Migration\TableBuilder;
use Doctrine\DBAL\Schema\Schema;

final class Version20261118030000 extends SchemaMigration
{
	public function getDescription(): string
	{
		return 'Plán pravidelných záloh instalace (backup_settings)';
	}

	public function up(Schema $schema): void
	{
		$this->createTable('backup_settings', static function (TableBuilder $table): void {
			$table->id()
				->boolean('enabled')
				->string('frequency', 20)
				->integer('hour')
				->integer('weekday')
				->integer('month_day')
				->string('content', 20)
				->integer('keep')
				->datetime('last_run_at', nullable: true)
				->boolean('last_succeeded', nullable: true)
				->text('last_message', nullable: true);
		});
	}

	public function down(Schema $schema): void
	{
		$this->dropTable('backup_settings');
	}
}
