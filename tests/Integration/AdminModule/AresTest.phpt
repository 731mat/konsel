<?php

declare(strict_types=1);

use App\Model\Ares\AresClient;
use App\Model\Ares\AresFacade;
use App\Model\Shared\Exception\ValidationException;
use App\Model\User\Role;
use Nette\Application\Responses\JsonResponse;
use Tester\Assert;
use Tests\Support\ContainerFactory;
use Tests\Support\FakeAresClient;
use Tests\Support\Fixtures;
use Tests\Support\MeetingScenario;
use Tests\Support\PresenterTester;
use Tests\Support\TestDatabase;

require __DIR__ . '/../../bootstrap.php';

/**
 * @return array<string, mixed>
 */
function payload(Nette\Application\Response $response): array
{
	Assert::type(JsonResponse::class, $response);
	assert($response instanceof JsonResponse);
	$data = json_decode((string) json_encode($response->getPayload()), true);
	assert(is_array($data));

	/** @var array<string, mixed> */
	return $data;
}

/**
 * @return array<string, mixed>
 */
function subject(Nette\Application\Response $response): array
{
	$subject = payload($response)['subject'] ?? null;
	assert(is_array($subject));

	/** @var array<string, mixed> */
	return $subject;
}

test('facade explains invalid, unknown and unavailable lookups', function (): void {
	$container = ContainerFactory::create();
	$ares = $container->getByType(AresFacade::class);
	$client = $container->getByType(AresClient::class);
	assert($client instanceof FakeAresClient);

	Assert::same('Silnice Morava s.r.o.', $ares->lookup('25 596 641')->name);
	Assert::exception(fn() => $ares->lookup('12345678'), ValidationException::class, 'IČO nemá platný kontrolní součet.');
	Assert::exception(fn() => $ares->lookup('27074358'), ValidationException::class, 'V ARES není žádný subjekt s IČO 27074358.');
	$client->unavailable = true;
	Assert::exception(fn() => $ares->lookup('25596641'), ValidationException::class, 'ARES teď neodpovídá. Zkuste to za chvíli, nebo údaje vyplňte ručně.');
});

test('settings and agenda form offer ARES lookup and return subject data', function (): void {
	$container = ContainerFactory::create();
	TestDatabase::migrate($container);
	$scenario = MeetingScenario::create($container);
	$tester = new PresenterTester($container);
	$tester->loginAs((new Fixtures($container))->user($scenario->municipality, 'spravce@neplachovice.cz', Role::Admin));

	Assert::contains('data-ares-lookup="/admin/settings?do=aresLookup"', $tester->render('Admin:Settings'));
	$settings = subject($tester->run('Admin:Settings', 'default', [
		'do' => 'aresLookup',
	], post: [
		'ico' => '00300535',
	]));
	Assert::same('Obec Neplachovice', $settings['name']);
	Assert::same('747 74', $settings['postalCode']);

	$params = [
		'meeting' => $scenario->meeting->getId(),
	];
	Assert::contains('data-ares-insert="', $tester->render('Admin:Agenda', 'add', $params));
	$agenda = subject($tester->run('Admin:Agenda', 'add', $params + [
		'do' => 'aresLookup',
	], post: [
		'ico' => '25596641',
	]));
	Assert::same('Silnice Morava s.r.o., se sídlem Průmyslová 5, 74601 Opava, IČO 25596641', $agenda['sentence']);
	Assert::same([
		'error' => 'IČO musí obsahovat nejvýše 8 číslic.',
	], payload($tester->run('Admin:Agenda', 'add', $params + [
		'do' => 'aresLookup',
	], post: [
		'ico' => 'abc',
	])));
});
