<?php

declare(strict_types=1);

namespace App\Migrations;

use App\Core\Doctrine\Migration\ColumnFactory as C;
use App\Core\Doctrine\Migration\SchemaMigration;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\TableEditor;

final class Version20261117210000 extends SchemaMigration
{
	public function getDescription(): string
	{
		return 'Moduly obce: vypnuté části aplikace (municipality.disabled_modules, null = vše zapnuto)';
	}

	public function up(Schema $schema): void
	{
		$this->alterTable('municipality', static function (TableEditor $table): void {
			$table->addColumn(C::json('disabled_modules', nullable: true));
		});
	}

	public function down(Schema $schema): void
	{
		$this->alterTable('municipality', static function (TableEditor $table): void {
			$table->dropColumnByUnquotedName('disabled_modules');
		});
	}
}
