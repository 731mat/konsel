<?php

declare(strict_types=1);

namespace App\Migrations;

use App\Core\Doctrine\Migration\ColumnFactory as C;
use App\Core\Doctrine\Migration\SchemaMigration;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\TableEditor;

final class Version20261116235500 extends SchemaMigration
{
	public function getDescription(): string
	{
		return 'Podněty občanů: kontakt a IP odesílatele (project)';
	}

	public function up(Schema $schema): void
	{
		$this->alterTable('project', static function (TableEditor $table): void {
			$table
				->addColumn(C::string('submitter_email', 180, nullable: true))
				->addColumn(C::string('submitter_ip', 45, nullable: true));
		});
	}

	public function down(Schema $schema): void
	{
		$this->alterTable('project', static function (TableEditor $table): void {
			$table->dropColumnByUnquotedName('submitter_email')->dropColumnByUnquotedName('submitter_ip');
		});
	}
}
