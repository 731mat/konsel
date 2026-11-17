<?php

declare(strict_types=1);

namespace App\Model\Shared\RichText;

/**
 * Převede vstup z editoru na bezpečné HTML (povolené jen formátovací značky a odkazy http/https/mailto).
 * Vstup bez HTML značek se bere jako prostý text (formulář bez JavaScriptu).
 */
interface HtmlSanitizer
{
	public function sanitize(string $input): RichText;
}
