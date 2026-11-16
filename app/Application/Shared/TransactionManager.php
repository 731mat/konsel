<?php

declare(strict_types=1);

namespace App\Application\Shared;

/**
 * Hranice transakce pro use-case. Po úspěšném callbacku se změny uloží (flush + commit),
 * při výjimce se vše vrátí.
 */
interface TransactionManager
{
	/**
	 * @template T
	 * @param callable(): T $operation
	 * @return T
	 */
	public function run(callable $operation): mixed;
}
