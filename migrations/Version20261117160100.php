<?php

declare(strict_types=1);

namespace App\Migrations;

use App\Core\Doctrine\Migration\ColumnFactory as C;
use App\Core\Doctrine\Migration\SchemaMigration;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\TableEditor;

final class Version20261117160100 extends SchemaMigration
{
	public function getDescription(): string
	{
		return 'Poznámka ke stavu usnesení – revokace (resolution.status_note)';
	}

	public function up(Schema $schema): void
	{
		$this->alterTable('resolution', static function (TableEditor $table): void {
			$table->addColumn(C::text('status_note', nullable: true));
		});
	}

	public function down(Schema $schema): void
	{
		$this->alterTable('resolution', static function (TableEditor $table): void {
			$table->dropColumnByUnquotedName('status_note');
		});
	}
}
