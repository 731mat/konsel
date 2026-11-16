<?php

declare(strict_types=1);

use App\Model\Ai\AiClient;
use App\Model\Ai\AiException;
use App\Model\Ai\AiFacade;
use App\Model\Ai\AiMessage;
use App\Model\Ai\Command\SaveAiSettings;
use App\Model\Shared\Exception\InvalidStateException;
use App\Model\Shared\Exception\ValidationException;
use Doctrine\DBAL\Connection;
use Tester\Assert;
use Tests\Support\ContainerFactory;
use Tests\Support\FakeAiClient;
use Tests\Support\Fixtures;
use Tests\Support\TestDatabase;

require __DIR__ . '/../../../bootstrap.php';

const ApiKey = 'sk-proj-TESTKEY1234567890abcdefXYZ9';

/**
 * @return array{AiFacade, FakeAiClient, int, Nette\DI\Container}
 */
function bootAi(): array
{
	$container = ContainerFactory::create();
	TestDatabase::migrate($container);
	$municipality = (new Fixtures($container))->municipality();
	$client = $container->getByType(AiClient::class);
	assert($client instanceof FakeAiClient);

	return [$container->getByType(AiFacade::class), $client, $municipality->getId(), $container];
}

test('api key is stored encrypted and used for calls', function (): void {
	[$facade, $client, $municipalityId, $container] = bootAi();
	Assert::false($facade->isAvailable($municipalityId));

	$facade->saveSettings($municipalityId, new SaveAiSettings('gpt-5-mini', true, '  ' . ApiKey . ' '));

	$raw = $container->getByType(Connection::class)->fetchOne('SELECT api_key_encrypted FROM ai_settings');
	assert(is_string($raw));
	Assert::notContains('TESTKEY', $raw);
	Assert::true($facade->isAvailable($municipalityId));
	Assert::same('…XYZ9', $facade->getSettings($municipalityId)?->getApiKeyHint());

	$completion = $facade->complete($municipalityId, [AiMessage::user('Ahoj')]);
	Assert::same('Návrh textu od AI.', $completion->text);
	Assert::same([['complete', ApiKey, 'gpt-5-mini']], $client->calls);
});

test('saving without a key keeps the stored one', function (): void {
	[$facade, $client, $municipalityId] = bootAi();
	$facade->saveSettings($municipalityId, new SaveAiSettings('gpt-5-mini', true, ApiKey));
	$facade->saveSettings($municipalityId, new SaveAiSettings('gpt-5', true, ''));

	$facade->testConnection($municipalityId);
	Assert::same([['verify', ApiKey, 'gpt-5']], $client->calls);
	Assert::notNull($facade->getSettings($municipalityId)?->getVerifiedAt());
});

test('invalid key format is rejected before anything is stored', function (): void {
	[$facade, , $municipalityId] = bootAi();

	$e = Assert::exception(fn() => $facade->saveSettings($municipalityId, new SaveAiSettings('gpt-5-mini', true, 'heslo123')), ValidationException::class);
	Assert::same('apiKey', $e?->field);
	Assert::null($facade->getSettings($municipalityId));
});

test('failed connection test is reported and not marked as verified', function (): void {
	[$facade, $client, $municipalityId] = bootAi();
	Assert::exception(fn() => $facade->testConnection($municipalityId), InvalidStateException::class, 'Nejdřív uložte API klíč OpenAI.');

	$facade->saveSettings($municipalityId, new SaveAiSettings('gpt-5-mini', true, ApiKey));
	$client->failWith = new AiException('OpenAI odmítlo API klíč.');

	Assert::exception(fn() => $facade->testConnection($municipalityId), AiException::class, 'OpenAI odmítlo API klíč.');
	Assert::null($facade->getSettings($municipalityId)?->getVerifiedAt());
});

test('disabled AI or removed key blocks completions', function (): void {
	[$facade, $client, $municipalityId] = bootAi();
	$facade->saveSettings($municipalityId, new SaveAiSettings('gpt-5-mini', false, ApiKey));

	Assert::exception(fn() => $facade->complete($municipalityId, [AiMessage::user('x')]), InvalidStateException::class);

	$facade->saveSettings($municipalityId, new SaveAiSettings('gpt-5-mini', true));
	$facade->removeApiKey($municipalityId);
	Assert::false($facade->isAvailable($municipalityId));
	Assert::exception(fn() => $facade->complete($municipalityId, [AiMessage::user('x')]), InvalidStateException::class);
	Assert::same([], $client->calls);
});
