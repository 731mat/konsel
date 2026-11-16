<?php

declare(strict_types=1);

use App\Model\Ai\AiSettings;
use App\Model\Municipality\Municipality;
use App\Model\Shared\Exception\ValidationException;
use Tester\Assert;

require __DIR__ . '/../../../bootstrap.php';

test('AI is ready only with key and enabled switch, changes reset verification', function (): void {
	$settings = new AiSettings(new Municipality('Neplachovice', 'neplachovice'));
	Assert::false($settings->isReady());

	$settings->configure('gpt-5-mini', true);
	Assert::false($settings->isReady(), 'bez klíče');

	$settings->changeApiKey('v1:šifra', 'sk-proj-1234567890abcdWXYZ');
	Assert::true($settings->isReady());
	Assert::same('…WXYZ', $settings->getApiKeyHint());

	$settings->markVerified(new DateTimeImmutable('2026-11-16 10:00'));
	$settings->configure('gpt-5-mini', false);
	Assert::notNull($settings->getVerifiedAt(), 'vypnutí neruší ověření');
	Assert::false($settings->isReady());

	$settings->configure('gpt-5', true);
	Assert::null($settings->getVerifiedAt(), 'jiný model je potřeba ověřit znovu');

	$settings->markVerified(new DateTimeImmutable('2026-11-16 10:00'));
	$settings->removeApiKey();
	Assert::null($settings->getVerifiedAt());
	Assert::false($settings->isReady());
});

test('model name is validated', function (): void {
	$settings = new AiSettings(new Municipality('Neplachovice', 'neplachovice'));

	$e = Assert::exception(fn() => $settings->configure('gpt 5; drop table', true), ValidationException::class);
	Assert::same('model', $e?->field);
	Assert::noError(fn() => $settings->configure('ft:gpt-4.1-mini:obec:abc123', true));
});
