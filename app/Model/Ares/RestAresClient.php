<?php

declare(strict_types=1);

namespace App\Model\Ares;

use App\Model\Shared\ValueObject\Ico;
use Nette\Caching\Cache;
use Nette\Caching\Storage;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * ARES přes REST API Ministerstva financí (veřejné, bez klíče). Odpovědi se drží 24 hodin v cache.
 */
final readonly class RestAresClient implements AresClient
{
	private const string CacheExpiration = '24 hours';

	private Cache $cache;

	public function __construct(
		private HttpClientInterface $httpClient,
		Storage $storage,
		private string $baseUrl = 'https://ares.gov.cz/ekonomicke-subjekty-v-be/rest',
		private float $timeout = 8.0,
	) {
		$this->cache = new Cache($storage, 'konsel.ares');
	}

	public function findByIco(Ico $ico): ?AresSubject
	{
		$data = $this->cache->load($ico->value);
		if (!is_array($data)) {
			$data = $this->fetch($ico);
			$this->cache->save($ico->value, $data, [
				Cache::Expire => self::CacheExpiration,
			]);
		}

		return isset($data['ico']) ? self::subject($data) : null;
	}

	/**
	 * @return array<mixed> odpověď ARES, prázdné pole = nenalezeno
	 */
	private function fetch(Ico $ico): array
	{
		try {
			$response = $this->httpClient->request('GET', "{$this->baseUrl}/ekonomicke-subjekty/{$ico->value}", [
				'headers' => [
					'Accept' => 'application/json',
					'User-Agent' => 'Konsel/1.0',
				],
				'timeout' => $this->timeout,
				'max_duration' => $this->timeout * 2,
			]);
			$status = $response->getStatusCode();
			if ($status === 404) {
				return [];
			}

			if ($status >= 400) {
				throw new AresException("ARES vrátil chybu {$status}.");
			}

			return $response->toArray();
		} catch (ExceptionInterface $e) {
			throw new AresException('Nepodařilo se spojit s ARES: ' . $e->getMessage(), 0, $e);
		}
	}

	/**
	 * @param array<mixed> $data
	 */
	private static function subject(array $data): AresSubject
	{
		$seat = is_array($data['sidlo'] ?? null) ? $data['sidlo'] : [];
		$text = static fn(string $key): ?string => isset($seat[$key]) && is_scalar($seat[$key]) && trim((string) $seat[$key]) !== '' ? trim((string) $seat[$key]) : null;

		$number = $text('cisloDomovni');
		if ($number !== null && $text('cisloOrientacni') !== null) {
			$number .= '/' . $text('cisloOrientacni') . ($text('cisloOrientacniPismeno') ?? '');
		}

		$city = $text('nazevObce');
		$street = match (true) {
			$text('nazevUlice') !== null => trim($text('nazevUlice') . ' ' . $number),
			$text('nazevCastiObce') !== null && $text('nazevCastiObce') !== $city => trim($text('nazevCastiObce') . ' ' . $number),
			$number !== null => "č. p. {$number}",
			default => null,
		};
		$postalCode = $text('psc');
		$postalCode = $postalCode !== null && strlen($postalCode) === 5 ? substr($postalCode, 0, 3) . ' ' . substr($postalCode, 3) : $postalCode;
		$address = $text('textovaAdresa') ?? implode(', ', array_filter([$street, trim(($postalCode ?? '') . ' ' . ($city ?? ''))], static fn(?string $part): bool => $part !== null && $part !== ''));

		return new AresSubject(
			ico: is_scalar($data['ico'] ?? null) ? (string) $data['ico'] : '',
			name: is_string($data['obchodniJmeno'] ?? null) ? trim($data['obchodniJmeno']) : '',
			street: $street,
			city: $city,
			postalCode: $postalCode,
			address: $address,
			dic: is_string($data['dic'] ?? null) ? $data['dic'] : null,
		);
	}
}
