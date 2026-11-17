<?php

declare(strict_types=1);

namespace App\Migrations;

use App\Core\Doctrine\Migration\ColumnFactory as C;
use App\Core\Doctrine\Migration\SchemaMigration;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\TableEditor;

final class Version20261117090100 extends SchemaMigration
{
	public function getDescription(): string
	{
		return 'Barvy obce v aplikaci (municipality.primary_color, accent_color)';
	}

	public function up(Schema $schema): void
	{
		$this->alterTable('municipality', static function (TableEditor $table): void {
			$table
				->addColumn(C::string('primary_color', 7, nullable: true))
				->addColumn(C::string('accent_color', 7, nullable: true));
		});
	}

	public function down(Schema $schema): void
	{
		$this->alterTable('municipality', static function (TableEditor $table): void {
			$table->dropColumnByUnquotedName('primary_color')->dropColumnByUnquotedName('accent_color');
		});
	}
}
