<?php

declare(strict_types=1);

namespace App\Model\Search;

use Doctrine\DBAL\Connection;

/**
 * Index nad SQLite FTS5 (tabulka search_index, tokenizer unicode61 remove_diacritics 2 – „dilo“ najde „dílo“).
 * Rowid = kód typu × miliarda + ID entity, takže přepsání i smazání jednoho záznamu je rychlé.
 * Obec se filtruje vždy explicitně – na ruční SQL se TenantFilter nevztahuje.
 */
final readonly class SqliteFtsSearchIndex implements SearchIndex
{
	private const string Table = 'search_index';

	public function __construct(
		private Connection $connection,
	) {}

	public function index(SearchDocument $document): void
	{
		$rowId = $document->type->rowId($document->entityId);
		$this->connection->executeStatement('DELETE FROM ' . self::Table . ' WHERE rowid = ?', [$rowId]);
		$this->connection->insert(self::Table, [
			'rowid' => $rowId,
			'title' => $document->title,
			'content' => $document->content,
			'type' => $document->type->value,
			'entity_id' => $document->entityId,
			'target_id' => $document->targetId,
			'municipality_id' => $document->municipalityId,
			'meeting_id' => $document->meetingId,
			'agenda_item_id' => $document->agendaItemId,
			'project_id' => $document->projectId,
			'body_id' => $document->bodyId,
			'year' => $document->year,
			'context' => $document->context,
		]);
	}

	public function remove(SearchDocumentType $type, int $entityId): void
	{
		$this->connection->executeStatement('DELETE FROM ' . self::Table . ' WHERE rowid = ?', [$type->rowId($entityId)]);
	}

	public function removeByMeeting(int $meetingId): void
	{
		$this->connection->executeStatement('DELETE FROM ' . self::Table . ' WHERE meeting_id = ?', [$meetingId]);
	}

	public function removeByAgendaItem(int $agendaItemId): void
	{
		$this->connection->executeStatement('DELETE FROM ' . self::Table . ' WHERE agenda_item_id = ?', [$agendaItemId]);
	}

	public function removeByProject(int $projectId): void
	{
		$this->connection->executeStatement('DELETE FROM ' . self::Table . ' WHERE project_id = ?', [$projectId]);
	}

	public function search(SearchQuery $query): SearchResult
	{
		$terms = $query->terms();
		if ($terms === []) {
			return SearchResult::empty();
		}

		// každé slovo v uvozovkách (žádná syntaxe FTS od uživatele) a s hvězdičkou: „rozpoč“ najde „rozpočet“
		$match = implode(' ', array_map(static fn(string $term): string => '"' . str_replace('"', '', $term) . '"*', $terms));
		$where = self::Table . ' MATCH ? AND municipality_id = ?';
		$params = [$match, $query->municipalityId];
		if ($query->type !== null) {
			$where .= ' AND type = ?';
			$params[] = $query->type->value;
		}

		foreach ($query->excludedTypes as $excluded) {
			$where .= ' AND type != ?';
			$params[] = $excluded->value;
		}

		if ($query->bodyId !== null) {
			$where .= ' AND body_id = ?';
			$params[] = $query->bodyId;
		}

		if ($query->year !== null) {
			$where .= ' AND year = ?';
			$params[] = $query->year;
		}

		$total = self::int($this->connection->fetchOne('SELECT COUNT(*) FROM ' . self::Table . " WHERE {$where}", $params));
		if ($total === 0) {
			return SearchResult::empty();
		}

		$open = HighlightedText::Open;
		$close = HighlightedText::Close;
		$rows = $this->connection->fetchAllAssociative(
			'SELECT type, entity_id, target_id, context,'
			. ' highlight(' . self::Table . ", 0, '{$open}', '{$close}') AS title,"
			. ' snippet(' . self::Table . ", 1, '{$open}', '{$close}', '…', 24) AS snippet"
			. ' FROM ' . self::Table . " WHERE {$where}"
			// šablony až za skutečnými zasedáními, body a usneseními
			. " ORDER BY type LIKE '%_template', bm25(" . self::Table . ', 10.0, 1.0) LIMIT ? OFFSET ?',
			[...$params, $query->limit, $query->offset],
		);

		$hits = [];
		foreach ($rows as $row) {
			$type = SearchDocumentType::tryFrom(self::string($row['type']));
			if ($type === null) {
				continue;
			}

			$hits[] = new SearchHit(
				$type,
				self::int($row['entity_id']),
				self::int($row['target_id']),
				HighlightedText::fromMarked(self::string($row['title'])),
				HighlightedText::fromMarked(self::string($row['snippet'])),
				self::string($row['context']),
			);
		}

		return new SearchResult($hits, $total);
	}

	public function clear(): void
	{
		$this->connection->executeStatement('DELETE FROM ' . self::Table);
	}

	private static function string(mixed $value): string
	{
		return is_scalar($value) ? (string) $value : '';
	}

	private static function int(mixed $value): int
	{
		return is_numeric($value) ? (int) $value : 0;
	}
}
