<?php

declare(strict_types=1);

namespace App\Model\Document;

/**
 * Vygenerovaný dokument připravený k odeslání (vzniká na požádání, do archivu ho uloží DocumentArchiveFacade).
 */
final readonly class RenderedDocument
{
	public function __construct(
		public string $fileName,
		public string $content,
		public string $mimeType = 'application/pdf',
	) {}
}
