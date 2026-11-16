<?php

declare(strict_types=1);

namespace App\Model\Ai;

/**
 * Odpověď jazykového modelu včetně spotřeby tokenů (pro log použití v T37).
 */
final readonly class AiCompletion
{
	public function __construct(
		public string $text,
		public string $model,
		public int $inputTokens = 0,
		public int $outputTokens = 0,
	) {}
}
