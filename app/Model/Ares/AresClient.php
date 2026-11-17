<?php

declare(strict_types=1);

namespace App\Model\Ares;

use App\Model\Shared\ValueObject\Ico;

/**
 * Registr ARES (implementace RestAresClient, v testech Tests\Support\FakeAresClient).
 */
interface AresClient
{
	/**
	 * @return ?AresSubject null = subjekt s tímto IČO neexistuje
	 * @throws AresException
	 */
	public function findByIco(Ico $ico): ?AresSubject;
}
