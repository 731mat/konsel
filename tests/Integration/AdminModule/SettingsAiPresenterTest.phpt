<?php

declare(strict_types=1);

use App\Model\Ai\AiClient;
use App\Model\Ai\AiException;
use App\Model\Ai\AiFacade;
use App\Model\User\Role;
use Nette\Application\BadRequestException;
use Tester\Assert;
use Tests\Support\ContainerFactory;
use Tests\Support\FakeAiClient;
use Tests\Support\Fixtures;
use Tests\Support\PresenterTester;
use Tests\Support\TestDatabase;

require __DIR__ . '/../../bootstrap.php';

/**
 * @return array{PresenterTester, AiFacade, FakeAiClient, int, Fixtures}
 */
function bootAiSettings(Role $role = Role::Admin): array
{
	$container = ContainerFactory::create();
	TestDatabase::migrate($container);
	$fixtures = new Fixtures($container);
	$municipality = $fixtures->municipality();
	$tester = new PresenterTester($container);
	$tester->loginAs($fixtures->user($municipality, role: $role));
	$client = $container->getByType(AiClient::class);
	assert($client instanceof FakeAiClient);

	return [$tester, $container->getByType(AiFacade::class), $client, $municipality->getId(), $fixtures];
}

test('admin saves the key, sees only its end and can test the connection', function (): void {
	[$tester, $facade, $client, $municipalityId] = bootAiSettings();

	Assert::contains('API klíč zatím není zadaný', $tester->render('Admin:Settings', 'ai'));

	PresenterTester::assertRedirect($tester->submitForm('Admin:Settings', 'aiForm', [
		'apiKey' => 'sk-proj-ABCDEFGHIJKLMNOPQRSTUV7890',
		'model' => 'gpt-5-mini',
		'enabled' => 'on',
	], action: 'ai'));
	Assert::true($facade->isAvailable($municipalityId));

	$html = $tester->render('Admin:Settings', 'ai');
	Assert::contains('sk-…7890', $html);
	Assert::notContains('ABCDEFGHIJ', $html);
	Assert::contains('Připojení zatím nebylo ověřeno.', $html);

	PresenterTester::assertRedirect($tester->run('Admin:Settings', 'ai', [
		'do' => 'testAi',
	], post: [
		'confirm' => '1',
	]));
	Assert::same('verify', $client->calls[0][0] ?? null);
	Assert::contains('Připojení ověřeno', $tester->render('Admin:Settings', 'ai'));
});

test('invalid key is shown as form error', function (): void {
	[$tester] = bootAiSettings();

	$html = PresenterTester::renderResponse($tester->submitForm('Admin:Settings', 'aiForm', [
		'apiKey' => 'moje heslo',
		'model' => 'gpt-5-mini',
	], action: 'ai'));

	Assert::contains('API klíč OpenAI začíná „sk-“', $html);
});

test('failed connection test shows the reason', function (): void {
	[$tester, $facade, $client, $municipalityId] = bootAiSettings();
	$facade->saveSettings($municipalityId, new App\Model\Ai\Command\SaveAiSettings('gpt-5-mini', true, 'sk-proj-ABCDEFGHIJKLMNOPQRSTUV7890'));
	$client->failWith = new AiException('OpenAI odmítlo API klíč.');

	$response = $tester->run('Admin:Settings', 'ai', [
		'do' => 'testAi',
	], post: [
		'confirm' => '1',
	]);
	Assert::contains('OpenAI odmítlo API klíč.', $tester->followRedirect($response, 'Admin:Settings', 'ai'));
});

test('editor cannot open AI settings', function (): void {
	[$tester] = bootAiSettings(Role::Editor);

	$e = Assert::exception(fn() => $tester->run('Admin:Settings', 'ai'), BadRequestException::class);
	Assert::same(403, $e?->getHttpCode());
});
