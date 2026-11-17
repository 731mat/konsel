<?php

declare(strict_types=1);

namespace App\Model\Search;

final readonly class SearchHit
{
	public function __construct(
		public SearchDocumentType $type,
		public int $entityId,
		public int $targetId,
		public HighlightedText $title,
		public HighlightedText $snippet,
		public string $context,
	) {}
}
