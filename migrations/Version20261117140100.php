<?php

declare(strict_types=1);

namespace App\Migrations;

use App\Core\Doctrine\Migration\ColumnFactory as C;
use App\Core\Doctrine\Migration\SchemaMigration;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\TableEditor;

final class Version20261117140100 extends SchemaMigration
{
	public function getDescription(): string
	{
		return 'Údaje do zápisu: počet hostů a poznámka (meeting)';
	}

	public function up(Schema $schema): void
	{
		$this->alterTable('meeting', static function (TableEditor $table): void {
			$table
				->addColumn(C::integer('guest_count', nullable: true))
				->addColumn(C::text('minutes_note', nullable: true));
		});
	}

	public function down(Schema $schema): void
	{
		$this->alterTable('meeting', static function (TableEditor $table): void {
			$table->dropColumnByUnquotedName('guest_count')->dropColumnByUnquotedName('minutes_note');
		});
	}
}
