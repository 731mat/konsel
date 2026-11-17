<?php

declare(strict_types=1);

namespace App\UI\Help;

/**
 * Stránka wiki z doc/wiki/*.md – slug = název souboru bez přípony, titulek = první nadpis.
 */
final readonly class WikiPage
{
	public function __construct(
		public string $slug,
		public string $title,
		public string $path,
	) {}
}
