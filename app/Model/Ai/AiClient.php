<?php

declare(strict_types=1);

namespace App\Model\Ai;

/**
 * Jazykový model (implementace OpenAiClient, v testech Tests\Support\FakeAiClient).
 * Klíč a model dostává při každém volání – každá obec má vlastní nastavení.
 */
interface AiClient
{
	/**
	 * Ověří klíč a dostupnost modelu bez spotřeby tokenů.
	 *
	 * @throws AiException
	 */
	public function verify(string $apiKey, string $model): void;

	/**
	 * @param non-empty-list<AiMessage> $messages
	 * @throws AiException
	 */
	public function complete(string $apiKey, string $model, array $messages): AiCompletion;
}
