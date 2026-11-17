<?php

declare(strict_types=1);

namespace App\Migrations;

use App\Core\Doctrine\Migration\SchemaMigration;
use App\Core\Doctrine\Migration\TableBuilder;
use Doctrine\DBAL\Schema\ForeignKeyConstraint\ReferentialAction;
use Doctrine\DBAL\Schema\Schema;

final class Version20261117200000 extends SchemaMigration
{
	public function getDescription(): string
	{
		return 'Historie znovuotevření zasedání s důvodem (meeting_reopening)';
	}

	public function up(Schema $schema): void
	{
		$this->createTable('meeting_reopening', static function (TableBuilder $table): void {
			$table->id()
				->foreignKey('municipality_id', 'municipality', onDelete: ReferentialAction::CASCADE)
				->foreignKey('meeting_id', 'meeting', onDelete: ReferentialAction::CASCADE)
				->string('from_status', 20)
				->string('to_status', 20)
				->text('reason')
				->foreignKey('reopened_by_id', 'app_user', nullable: true, onDelete: ReferentialAction::SET_NULL)
				->string('reopened_by_name', 200)
				->datetime('reopened_at');
		});
	}

	public function down(Schema $schema): void
	{
		$this->dropTable('meeting_reopening');
	}
}
