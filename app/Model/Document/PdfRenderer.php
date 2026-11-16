<?php

declare(strict_types=1);

namespace App\Model\Document;

/**
 * Převod HTML na PDF (implementace MpdfRenderer).
 */
interface PdfRenderer
{
	/**
	 * @return string obsah PDF souboru
	 */
	public function render(string $html, string $title): string;
}
