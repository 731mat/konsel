<?php

declare(strict_types=1);

namespace App\Migrations;

use App\Core\Doctrine\Migration\ColumnFactory as C;
use App\Core\Doctrine\Migration\SchemaMigration;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\TableEditor;

final class Version20261117150000 extends SchemaMigration
{
	public function getDescription(): string
	{
		return 'Řada čísel usnesení orgánu – 1/2 (body.numbering_scope s výchozí hodnotou)';
	}

	public function up(Schema $schema): void
	{
		$this->alterTable('body', static function (TableEditor $table): void {
			$table->addColumn(C::string('numbering_scope', 20, default: 'year'));
		});
	}

	public function down(Schema $schema): void
	{
		$this->alterTable('body', static function (TableEditor $table): void {
			$table->dropColumnByUnquotedName('numbering_scope');
		});
	}
}
