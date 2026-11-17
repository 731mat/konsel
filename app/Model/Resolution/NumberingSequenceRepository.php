<?php

declare(strict_types=1);

namespace App\Model\Resolution;

use App\Model\People\Body;

interface NumberingSequenceRepository
{
	/**
	 * Další číslo v řadě. Musí běžet v transakci – přidělení je atomické i při souběhu.
	 */
	public function next(Body $body, string $scopeKey): int;

	/**
	 * Vrátí číslo do řady, pokud je poslední přidělené (oprava hlasování hned po něm).
	 *
	 * @return bool false = číslo už není poslední, v řadě zůstane mezera
	 */
	public function release(Body $body, string $scopeKey, int $value): bool;

	/**
	 * Poslední přidělené číslo v řadě (0 = řada ještě nezačala).
	 */
	public function current(Body $body, string $scopeKey): int;

	/**
	 * Nastaví poslední přidělené číslo (navázání na papírovou řadu při zavedení Konšela).
	 */
	public function set(Body $body, string $scopeKey, int $lastValue): void;
}
