<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Model\Ai\AiClient;
use App\Model\Ai\AiCompletion;
use App\Model\Ai\AiException;

/**
 * AI klient pro testy – nikdy nevolá síť. Zaznamenává volání a umí simulovat chybu.
 */
final class FakeAiClient implements AiClient
{
	/**
	 * @var list<array{string, string, string}> [metoda, klíč, model]
	 */
	public array $calls = [];

	public ?AiException $failWith = null;

	public string $reply = 'Návrh textu od AI.';

	public function verify(string $apiKey, string $model): void
	{
		$this->calls[] = ['verify', $apiKey, $model];
		if ($this->failWith !== null) {
			throw $this->failWith;
		}
	}

	public function complete(string $apiKey, string $model, array $messages): AiCompletion
	{
		$this->calls[] = ['complete', $apiKey, $model];
		if ($this->failWith !== null) {
			throw $this->failWith;
		}

		return new AiCompletion($this->reply, $model, 12, 5);
	}
}
