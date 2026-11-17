<?php

declare(strict_types=1);

namespace App\Migrations;

use App\Core\Doctrine\Migration\SchemaMigration;
use App\Core\Doctrine\Migration\TableBuilder;
use Doctrine\DBAL\Schema\ForeignKeyConstraint\ReferentialAction;
use Doctrine\DBAL\Schema\Schema;

final class Version20261117120000 extends SchemaMigration
{
	public function getDescription(): string
	{
		return 'Docházka na zasedání (attendance)';
	}

	public function up(Schema $schema): void
	{
		$this->createTable('attendance', static function (TableBuilder $table): void {
			$table->id()
				->foreignKey('municipality_id', 'municipality', onDelete: ReferentialAction::CASCADE)
				->foreignKey('meeting_id', 'meeting', onDelete: ReferentialAction::CASCADE)
				->foreignKey('person_id', 'person', onDelete: ReferentialAction::CASCADE)
				->string('status', 20)
				->datetime('arrived_at', nullable: true)
				->datetime('left_at', nullable: true)
				->string('note', 500, nullable: true)
				->unique('meeting_id', 'person_id');
		});
	}

	public function down(Schema $schema): void
	{
		$this->dropTable('attendance');
	}
}
