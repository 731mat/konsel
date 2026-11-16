<?php

declare(strict_types=1);

namespace App\Model\Ai;

use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * OpenAI (GPT) přes REST API – Chat Completions a Models.
 */
final readonly class OpenAiClient implements AiClient
{
	public function __construct(
		private HttpClientInterface $httpClient,
		private string $baseUrl = 'https://api.openai.com/v1',
		private float $timeout = 60.0,
	) {}

	public function verify(string $apiKey, string $model): void
	{
		$this->send('GET', '/models/' . rawurlencode($model), $apiKey, null, $model, 15.0);
	}

	public function complete(string $apiKey, string $model, array $messages): AiCompletion
	{
		$data = $this->send('POST', '/chat/completions', $apiKey, [
			'model' => $model,
			'messages' => array_map(static fn(AiMessage $message): array => [
				'role' => $message->role->value,
				'content' => $message->content,
			], $messages),
		], $model, $this->timeout);

		$choices = $data['choices'] ?? null;
		$message = is_array($choices) && isset($choices[0]) && is_array($choices[0]) ? ($choices[0]['message'] ?? null) : null;
		$text = is_array($message) ? ($message['content'] ?? null) : null;
		if (!is_string($text)) {
			throw new AiException('OpenAI vrátilo odpověď v neočekávaném formátu.');
		}

		$usage = is_array($data['usage'] ?? null) ? $data['usage'] : [];

		return new AiCompletion(
			trim($text),
			is_string($data['model'] ?? null) ? $data['model'] : $model,
			is_int($usage['prompt_tokens'] ?? null) ? $usage['prompt_tokens'] : 0,
			is_int($usage['completion_tokens'] ?? null) ? $usage['completion_tokens'] : 0,
		);
	}

	/**
	 * @param array<string, mixed>|null $json
	 * @return array<mixed>
	 */
	private function send(string $method, string $path, string $apiKey, ?array $json, string $model, float $timeout): array
	{
		$options = [
			'auth_bearer' => $apiKey,
			'timeout' => $timeout,
			'max_duration' => $timeout * 2,
		];
		if ($json !== null) {
			$options['json'] = $json;
		}

		try {
			$response = $this->httpClient->request($method, $this->baseUrl . $path, $options);
			$status = $response->getStatusCode();
			if ($status >= 400) {
				throw new AiException(self::errorMessage($status, $response, $model));
			}

			return $response->toArray();
		} catch (ExceptionInterface $e) {
			throw new AiException('Nepodařilo se spojit s OpenAI: ' . $e->getMessage(), 0, $e);
		}
	}

	private static function errorMessage(int $status, ResponseInterface $response, string $model): string
	{
		$detail = '';
		try {
			$error = $response->toArray(false)['error'] ?? null;
			$detail = is_array($error) && is_string($error['message'] ?? null) ? $error['message'] : '';
		} catch (ExceptionInterface) {
			// tělo chyby není JSON
		}

		return match (true) {
			$status === 401 => 'OpenAI odmítlo API klíč. Zkontrolujte, že je platný a nebyl zrušen.',
			$status === 403 => 'API klíč nemá k této operaci oprávnění.' . ($detail === '' ? '' : " ({$detail})"),
			$status === 404 => "Model „{$model}“ není pro tento klíč dostupný.",
			$status === 429 => 'Překročený limit požadavků nebo vyčerpaný kredit u OpenAI.' . ($detail === '' ? '' : " ({$detail})"),
			$status >= 500 => "Služba OpenAI je dočasně nedostupná (HTTP {$status}). Zkuste to později.",
			default => "OpenAI vrátilo chybu HTTP {$status}." . ($detail === '' ? '' : " {$detail}"),
		};
	}
}
