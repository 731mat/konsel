<?php

declare(strict_types=1);

namespace App\Model\Search;

use App\Model\Shared\TransactionManager;

/**
 * Fulltextové hledání napříč zasedáními, body, usneseními, diskusemi, plánníčkem a šablonami.
 */
final readonly class SearchFacade
{
	public const int PageSize = 20;

	public function __construct(
		private SearchIndex $index,
		private SearchSource $source,
		private TransactionManager $transaction,
	) {}

	/**
	 * @param list<SearchDocumentType> $excludedTypes
	 */
	public function search(int $municipalityId, string $text, ?SearchDocumentType $type = null, ?int $bodyId = null, ?int $year = null, int $page = 1, array $excludedTypes = []): SearchResult
	{
		if ($type !== null && in_array($type, $excludedTypes, true)) {
			return SearchResult::empty();
		}

		return $this->index->search(new SearchQuery(
			$municipalityId,
			$text,
			$type,
			$bodyId,
			$year,
			self::PageSize,
			(max(1, $page) - 1) * self::PageSize,
			$excludedTypes,
		));
	}

	/**
	 * Postaví index znovu z dat (po nasazení, po změně pravidel indexace).
	 *
	 * @return int počet zaindexovaných záznamů
	 */
	public function reindex(): int
	{
		return $this->transaction->run(function (): int {
			$this->index->clear();
			$count = 0;
			foreach ($this->source->all() as $entity) {
				$document = SearchDocumentFactory::create($entity);
				if ($document !== null) {
					$this->index->index($document);
					$count++;
				}
			}

			return $count;
		});
	}
}
