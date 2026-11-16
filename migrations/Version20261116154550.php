<?php

declare(strict_types=1);

namespace App\Migrations;

use App\Infrastructure\Persistence\Migration\SchemaMigration;
use App\Infrastructure\Persistence\Migration\TableBuilder;
use Doctrine\DBAL\Schema\Schema;

final class Version20261116154550 extends SchemaMigration
{
	public function getDescription(): string
	{
		return 'Obec (municipality)';
	}

	public function up(Schema $schema): void
	{
		$this->createTable('municipality', static function (TableBuilder $table): void {
			$table->id()
				->string('name', 200)
				->string('slug', 100)
				->string('ico', 8, nullable: true)
				->timestamps()
				->unique('slug')
				->unique('ico');
		});
	}

	public function down(Schema $schema): void
	{
		$this->dropTable('municipality');
	}
}
