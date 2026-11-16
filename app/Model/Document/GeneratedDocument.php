<?php

declare(strict_types=1);

namespace App\Model\Document;

/**
 * Vygenerovaný dokument připravený k odeslání (zatím se neukládá – vzniká na požádání).
 */
final readonly class GeneratedDocument
{
	public function __construct(
		public string $fileName,
		public string $content,
		public string $mimeType = 'application/pdf',
	) {}
}
