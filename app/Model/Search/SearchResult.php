<?php

declare(strict_types=1);

namespace App\Model\Search;

final readonly class SearchResult
{
	/**
	 * @param list<SearchHit> $hits
	 */
	public function __construct(
		public array $hits,
		public int $total,
	) {}

	public static function empty(): self
	{
		return new self([], 0);
	}
}
