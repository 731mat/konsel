<?php

declare(strict_types=1);

namespace App\Model\Search;

/**
 * Fulltextový index (SQLite FTS5, později MySQL FULLTEXT). Hledá bez ohledu na diakritiku a velikost písmen.
 * Volá se uvnitř transakce use-casu – index je tak vždy v souladu s daty.
 */
interface SearchIndex
{
	/**
	 * Vloží nebo nahradí záznam (podle typu a ID entity).
	 */
	public function index(SearchDocument $document): void;

	public function remove(SearchDocumentType $type, int $entityId): void;

	/**
	 * Smaže vše, co patří k zasedání (body, usnesení, diskuse) – při smazání zasedání.
	 */
	public function removeByMeeting(int $meetingId): void;

	public function removeByAgendaItem(int $agendaItemId): void;

	public function removeByProject(int $projectId): void;

	public function search(SearchQuery $query): SearchResult;

	/**
	 * Smaže celý index (před přeindexováním).
	 */
	public function clear(): void;
}
