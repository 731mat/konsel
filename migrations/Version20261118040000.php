<?php

declare(strict_types=1);

namespace App\Migrations;

use App\Core\Doctrine\Migration\SchemaMigration;
use App\Core\Doctrine\Migration\TableBuilder;
use Doctrine\DBAL\Schema\ForeignKeyConstraint\ReferentialAction;
use Doctrine\DBAL\Schema\Schema;

final class Version20261118040000 extends SchemaMigration
{
	public function getDescription(): string
	{
		return 'Připojení AI asistentů přes MCP: aplikace OAuth, autorizační kódy, tokeny uživatelů';
	}

	public function up(Schema $schema): void
	{
		$this->createTable('oauth_client', static function (TableBuilder $table): void {
			$table->id()
				->string('client_id', 64)
				->string('secret_hash', 64, nullable: true)
				->string('name', 120)
				->json('redirect_uris')
				->string('auth_method', 30)
				->datetime('created_at')
				->unique('client_id');
		});

		$this->createTable('oauth_authorization_code', static function (TableBuilder $table): void {
			$table->id()
				->foreignKey('municipality_id', 'municipality', onDelete: ReferentialAction::CASCADE)
				->foreignKey('client_id', 'oauth_client', onDelete: ReferentialAction::CASCADE)
				->foreignKey('user_id', 'app_user', onDelete: ReferentialAction::CASCADE)
				->string('code_hash', 64)
				->string('redirect_uri', 2000)
				->string('code_challenge', 128)
				->string('scope', 10)
				->datetime('expires_at')
				->datetime('used_at', nullable: true)
				->unique('code_hash');
		});

		$this->createTable('ai_access_token', static function (TableBuilder $table): void {
			$table->id()
				->foreignKey('municipality_id', 'municipality', onDelete: ReferentialAction::CASCADE)
				->foreignKey('user_id', 'app_user', onDelete: ReferentialAction::CASCADE)
				->foreignKey('client_id', 'oauth_client', nullable: true, onDelete: ReferentialAction::CASCADE)
				->string('kind', 20)
				->string('name', 120)
				->string('scope', 10)
				->string('token_hash', 64)
				->string('refresh_token_hash', 64, nullable: true)
				->string('hint', 12, nullable: true)
				->datetime('created_at')
				->datetime('expires_at')
				->datetime('refresh_expires_at', nullable: true)
				->datetime('last_used_at', nullable: true)
				->datetime('revoked_at', nullable: true)
				->unique('token_hash')
				->unique('refresh_token_hash');
		});
	}

	public function down(Schema $schema): void
	{
		$this->dropTable('ai_access_token');
		$this->dropTable('oauth_authorization_code');
		$this->dropTable('oauth_client');
	}
}
