<?php

declare(strict_types=1);

namespace App\Model\Search;

/**
 * Jeden záznam v indexu: hledá se v názvu (větší váha) a obsahu, ostatní pole slouží k filtrům a odkazu.
 */
final readonly class SearchDocument
{
	/**
	 * @param int $targetId ID stránky, na kterou výsledek vede (bod u komentáře, úkol u diskuse v úkolníčku…)
	 * @param string $context krátký popis místa („3. zasedání · Zastupitelstvo · 9. 12. 2026“)
	 */
	public function __construct(
		public SearchDocumentType $type,
		public int $entityId,
		public int $targetId,
		public int $municipalityId,
		public string $title,
		public string $content,
		public string $context,
		public ?int $meetingId = null,
		public ?int $agendaItemId = null,
		public ?int $projectId = null,
		public ?int $bodyId = null,
		public ?int $year = null,
	) {}
}
