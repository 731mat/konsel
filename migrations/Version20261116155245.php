<?php

declare(strict_types=1);

namespace App\Migrations;

use App\Infrastructure\Persistence\Migration\SchemaMigration;
use App\Infrastructure\Persistence\Migration\TableBuilder;
use Doctrine\DBAL\Schema\ForeignKeyConstraint\ReferentialAction;
use Doctrine\DBAL\Schema\Schema;

final class Version20261116155245 extends SchemaMigration
{
	public function getDescription(): string
	{
		return 'Uživatelé úřadu (app_user) a pokusy o přihlášení (login_attempt)';
	}

	public function up(Schema $schema): void
	{
		$this->createTable('app_user', static function (TableBuilder $table): void {
			$table->id()
				->foreignKey('municipality_id', 'municipality', nullable: true, onDelete: ReferentialAction::CASCADE)
				->string('email', 180)
				->string('password_hash')
				->string('name', 150)
				->string('role', 20)
				->boolean('active')
				->boolean('must_change_password')
				->datetime('last_login_at', nullable: true)
				->timestamps()
				->unique('email');
		});

		$this->createTable('login_attempt', static function (TableBuilder $table): void {
			$table->id()
				->string('email', 180)
				->string('ip', 45)
				->boolean('successful')
				->datetime('attempted_at')
				->namedIndex('IDX_LOGIN_ATTEMPT_EMAIL', 'email', 'attempted_at')
				->namedIndex('IDX_LOGIN_ATTEMPT_IP', 'ip', 'attempted_at');
		});
	}

	public function down(Schema $schema): void
	{
		$this->dropTable('login_attempt');
		$this->dropTable('app_user');
	}
}
