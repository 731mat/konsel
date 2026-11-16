<?php

declare(strict_types=1);

namespace App\Migrations;

use App\Core\Doctrine\Migration\SchemaMigration;
use App\Core\Doctrine\Migration\TableBuilder;
use Doctrine\DBAL\Schema\ForeignKeyConstraint\ReferentialAction;
use Doctrine\DBAL\Schema\Schema;

final class Version20261116161919 extends SchemaMigration
{
	public function getDescription(): string
	{
		return 'Osoby, volební období, orgány a členství';
	}

	public function up(Schema $schema): void
	{
		$this->createTable('person', static function (TableBuilder $table): void {
			$table->id()
				->foreignKey('municipality_id', 'municipality', onDelete: ReferentialAction::CASCADE)
				->string('title_before', 50, nullable: true)
				->string('first_name', 100)
				->string('last_name', 100)
				->string('title_after', 50, nullable: true)
				->string('email', 180, nullable: true)
				->string('phone', 50, nullable: true)
				->timestamps();
		});

		$this->createTable('term', static function (TableBuilder $table): void {
			$table->id()
				->foreignKey('municipality_id', 'municipality', onDelete: ReferentialAction::CASCADE)
				->string('name', 50)
				->date('starts_on')
				->date('ends_on', nullable: true);
		});

		$this->createTable('body', static function (TableBuilder $table): void {
			$table->id()
				->foreignKey('municipality_id', 'municipality', onDelete: ReferentialAction::CASCADE)
				->foreignKey('term_id', 'term')
				->string('type', 20)
				->string('name', 200)
				->integer('seat_count')
				->string('numbering_mask', 100)
				->timestamps();
		});

		$this->createTable('membership', static function (TableBuilder $table): void {
			$table->id()
				->foreignKey('municipality_id', 'municipality', onDelete: ReferentialAction::CASCADE)
				->foreignKey('body_id', 'body', onDelete: ReferentialAction::CASCADE)
				->foreignKey('person_id', 'person')
				->string('member_function', 20)
				->date('valid_from')
				->date('valid_to', nullable: true);
		});
	}

	public function down(Schema $schema): void
	{
		$this->dropTable('membership');
		$this->dropTable('body');
		$this->dropTable('term');
		$this->dropTable('person');
	}
}
