<?php

declare(strict_types=1);

namespace App\Migrations;

use App\Core\Doctrine\Migration\SchemaMigration;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;

/**
 * Výjimka z ADR A4: fulltextový index je specifický pro platformu, proto raw SQL s větvením.
 * Tabulku search_index vynechává schema diff (SchemaAssetsFilter). Naplní ji `search:reindex`.
 */
final class Version20261117190000 extends SchemaMigration
{
	public function getDescription(): string
	{
		return 'Fulltextové hledání: virtuální tabulka search_index (SQLite FTS5, bez ohledu na diakritiku)';
	}

	public function up(Schema $schema): void
	{
		$this->abortIf(!$this->platform instanceof SQLitePlatform, 'Fulltextový index pro MySQL/MariaDB doplní T42 (MysqlFulltextSearchIndex).');
		$this->addSql(<<<'SQL'
			CREATE VIRTUAL TABLE search_index USING fts5(
				title,
				content,
				type UNINDEXED,
				entity_id UNINDEXED,
				target_id UNINDEXED,
				municipality_id UNINDEXED,
				meeting_id UNINDEXED,
				agenda_item_id UNINDEXED,
				project_id UNINDEXED,
				body_id UNINDEXED,
				year UNINDEXED,
				context UNINDEXED,
				tokenize = 'unicode61 remove_diacritics 2'
			)
			SQL);
	}

	public function down(Schema $schema): void
	{
		$this->addSql('DROP TABLE search_index');
	}
}
