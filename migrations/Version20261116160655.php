<?php

declare(strict_types=1);

namespace App\Migrations;

use App\Infrastructure\Persistence\Migration\ColumnFactory as C;
use App\Infrastructure\Persistence\Migration\SchemaMigration;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\TableEditor;

final class Version20261116160655 extends SchemaMigration
{
	public function getDescription(): string
	{
		return 'Obec: profil (oficiální název, adresa, kontakty, logo) – 1/2 přidání sloupců a dat';
	}

	public function up(Schema $schema): void
	{
		$this->alterTable('municipality', static function (TableEditor $table): void {
			$table
				->addColumn(C::string('official_name', 200, default: ''))
				->addColumn(C::string('street', 200, nullable: true))
				->addColumn(C::string('city', 100, nullable: true))
				->addColumn(C::string('postal_code', 6, nullable: true))
				->addColumn(C::string('email', 180, nullable: true))
				->addColumn(C::string('phone', 50, nullable: true))
				->addColumn(C::string('website', 255, nullable: true))
				->addColumn(C::integer('population', nullable: true))
				->addColumn(C::json('logo', nullable: true));
		});

		$this->addSql(sprintf(
			'UPDATE municipality SET official_name = %s',
			$this->platform->getConcatExpression("'Obec '", 'name'),
		));
	}

	public function down(Schema $schema): void
	{
		$this->alterTable('municipality', static function (TableEditor $table): void {
			foreach (['official_name', 'street', 'city', 'postal_code', 'email', 'phone', 'website', 'population', 'logo'] as $column) {
				$table->dropColumnByUnquotedName($column);
			}
		});
	}
}
