<?php

declare(strict_types=1);

namespace App\Migrations;

use App\Core\Doctrine\Migration\ColumnFactory as C;
use App\Core\Doctrine\Migration\SchemaMigration;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\TableEditor;

final class Version20261117140000 extends SchemaMigration
{
	public function getDescription(): string
	{
		return 'Diskuse k bodu a body doplněné na zasedání – 1/2 (agenda_item, sloupec s výchozí hodnotou)';
	}

	public function up(Schema $schema): void
	{
		$this->alterTable('agenda_item', static function (TableEditor $table): void {
			$table
				->addColumn(C::text('discussion', nullable: true))
				->addColumn(C::boolean('added_during_meeting', default: false));
		});
	}

	public function down(Schema $schema): void
	{
		$this->alterTable('agenda_item', static function (TableEditor $table): void {
			$table->dropColumnByUnquotedName('discussion')->dropColumnByUnquotedName('added_during_meeting');
		});
	}
}
