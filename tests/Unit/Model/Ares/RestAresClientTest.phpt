<?php

declare(strict_types=1);

use App\Model\Ares\AresException;
use App\Model\Ares\RestAresClient;
use App\Model\Shared\ValueObject\Ico;
use Nette\Caching\Storages\MemoryStorage;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Tester\Assert;

require __DIR__ . '/../../../bootstrap.php';

const AresMunicipality = '{"ico":"00300535","obchodniJmeno":"Obec Neplachovice","sidlo":{"kodStatu":"CZ","nazevObce":"Neplachovice","nazevUlice":"Na Návsi","cisloDomovni":16,"psc":74774,"textovaAdresa":"Na Návsi 16, 74774 Neplachovice"},"pravniForma":"801","dic":"CZ00300535"}';

test('subject is parsed from the ARES response and cached for 24 hours', function (): void {
	$requests = [];
	$http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$requests): MockResponse {
		$requests[] = [$method, $url, $options['headers'] ?? []];

		return new MockResponse(AresMunicipality, [
			'http_code' => 200,
		]);
	});
	$client = new RestAresClient($http, new MemoryStorage());

	$subject = $client->findByIco(new Ico('300535'));
	Assert::notNull($subject);
	assert($subject !== null);
	Assert::same('00300535', $subject->ico);
	Assert::same('Obec Neplachovice', $subject->name);
	Assert::same('Na Návsi 16', $subject->street);
	Assert::same('Neplachovice', $subject->city);
	Assert::same('747 74', $subject->postalCode);
	Assert::same('Obec Neplachovice, se sídlem Na Návsi 16, 74774 Neplachovice, IČO 00300535', $subject->toSentence());
	Assert::same('GET', $requests[0][0]);
	Assert::same('https://ares.gov.cz/ekonomicke-subjekty-v-be/rest/ekonomicke-subjekty/00300535', $requests[0][1]);
	Assert::false(str_contains((string) json_encode($requests[0][2]), '@'), 'žádný e-mail v hlavičkách');

	$client->findByIco(new Ico('00300535'));
	Assert::count(1, $requests, 'druhý dotaz z cache');
});

test('village without streets, unknown subject and outage', function (): void {
	$responses = [
		new MockResponse('{"ico":"25596641","obchodniJmeno":"Obec Malá Ves","sidlo":{"nazevObce":"Malá Ves","nazevCastiObce":"Malá Ves","cisloDomovni":12,"psc":12345}}'),
		new MockResponse('{"kod":"NENALEZENO","popis":"Subjekt nenalezen"}', [
			'http_code' => 404,
		]),
		new MockResponse('chyba', [
			'http_code' => 503,
		]),
	];
	$client = new RestAresClient(new MockHttpClient($responses), new MemoryStorage());

	$village = $client->findByIco(new Ico('25596641'));
	Assert::same('č. p. 12', $village?->street);
	Assert::same('č. p. 12, 123 45 Malá Ves', $village?->address);
	Assert::null($client->findByIco(new Ico('00300535')));
	Assert::exception(fn() => $client->findByIco(new Ico('27074358')), AresException::class, 'ARES vrátil chybu 503.');
});
