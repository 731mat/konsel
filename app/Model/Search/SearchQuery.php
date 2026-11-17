<?php

declare(strict_types=1);

namespace App\Model\Search;

final readonly class SearchQuery
{
	public const int MaxTerms = 10;

	public function __construct(
		public int $municipalityId,
		public string $text,
		public ?SearchDocumentType $type = null,
		public ?int $bodyId = null,
		public ?int $year = null,
		public int $limit = 20,
		public int $offset = 0,
		/**
		 * Druhy, které se nehledají (vypnutý modul obce)
		 *
		 * @var list<SearchDocumentType>
		 */
		public array $excludedTypes = [],
	) {}

	/**
	 * Slova dotazu bez interpunkce. Jednopísmenná slova (předložky „o“, „v“, „k“…) se vynechají.
	 *
	 * @return list<string>
	 */
	public function terms(): array
	{
		preg_match_all('~[\p{L}\p{N}]+~u', mb_strtolower($this->text), $matches);
		$terms = array_values(array_unique(array_filter($matches[0], static fn(string $term): bool => mb_strlen($term) > 1)));

		return array_slice($terms, 0, self::MaxTerms);
	}

	public function isEmpty(): bool
	{
		return $this->terms() === [];
	}
}
