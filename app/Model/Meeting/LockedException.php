<?php

declare(strict_types=1);

namespace App\Model\Meeting;

use App\Model\Shared\Exception\DomainException;

/**
 * Bod právě upravuje někdo jiný.
 */
final class LockedException extends DomainException
{
	public function __construct(
		public readonly string $holderName,
		public readonly \DateTimeImmutable $expiresAt,
	) {
		parent::__construct("Bod právě upravuje {$holderName}. Počkejte, až úpravy dokončí.");
	}
}
