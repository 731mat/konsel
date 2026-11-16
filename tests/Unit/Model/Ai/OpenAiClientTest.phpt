<?php

declare(strict_types=1);

use App\Model\Ai\AiException;
use App\Model\Ai\AiMessage;
use App\Model\Ai\OpenAiClient;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Tester\Assert;

require __DIR__ . '/../../../bootstrap.php';

test('verify asks for the model with bearer token', function (): void {
	$requests = [];
	$http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$requests): MockResponse {
		$headers = $options['normalized_headers'] ?? [];
		assert(is_array($headers) && is_array($headers['authorization'] ?? null));
		$requests[] = [$method, $url, $headers['authorization'][0] ?? null];

		return new MockResponse('{"id":"gpt-5-mini","object":"model"}', [
			'http_code' => 200,
		]);
	});

	(new OpenAiClient($http))->verify('sk-test-123', 'gpt-5-mini');

	Assert::same([['GET', 'https://api.openai.com/v1/models/gpt-5-mini', 'Authorization: Bearer sk-test-123']], $requests);
});

test('completion sends messages and reads text and token usage', function (): void {
	$body = null;
	$http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$body): MockResponse {
		assert(is_string($options['body'] ?? null));
		$body = json_decode($options['body'], true);

		return new MockResponse(json_encode([
			'model' => 'gpt-5-mini-2026-08-07',
			'choices' => [[
				'message' => [
					'role' => 'assistant',
					'content' => "  Zastupitelstvo schvaluje …  \n",
				],
			]],
			'usage' => [
				'prompt_tokens' => 42,
				'completion_tokens' => 7,
			],
		], JSON_THROW_ON_ERROR));
	});

	$completion = (new OpenAiClient($http))->complete('sk-test', 'gpt-5-mini', [
		AiMessage::system('Jsi asistent obce.'),
		AiMessage::user('Navrhni usnesení.'),
	]);

	Assert::same('Zastupitelstvo schvaluje …', $completion->text);
	Assert::same('gpt-5-mini-2026-08-07', $completion->model);
	Assert::same([42, 7], [$completion->inputTokens, $completion->outputTokens]);
	Assert::same([
		'model' => 'gpt-5-mini',
		'messages' => [
			[
				'role' => 'system',
				'content' => 'Jsi asistent obce.',
			],
			[
				'role' => 'user',
				'content' => 'Navrhni usnesení.',
			],
		],
	], $body);
});

test('HTTP errors are translated to user friendly messages', function (): void {
	$cases = [
		401 => 'OpenAI odmítlo API klíč.%A%',
		404 => 'Model „gpt-x“ není pro tento klíč dostupný.',
		429 => 'Překročený limit požadavků nebo vyčerpaný kredit u OpenAI. (You exceeded your current quota)',
		503 => 'Služba OpenAI je dočasně nedostupná (HTTP 503).%A%',
	];
	foreach ($cases as $status => $message) {
		$http = new MockHttpClient(new MockResponse('{"error":{"message":"You exceeded your current quota"}}', [
			'http_code' => $status,
		]));
		Assert::exception(fn() => (new OpenAiClient($http))->verify('sk-test', 'gpt-x'), AiException::class, $message);
	}

	$http = new MockHttpClient(new MockResponse('', [
		'error' => 'Could not resolve host',
	]));
	Assert::exception(fn() => (new OpenAiClient($http))->verify('sk-test', 'gpt-x'), AiException::class, 'Nepodařilo se spojit s OpenAI%A%');
});
