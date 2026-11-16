<?php

declare(strict_types=1);

namespace App\Domain\Shared;

/**
 * Entita s časem vytvoření a poslední změny. Hodnoty nastavuje Doctrine listener podle ClockInterface.
 */
interface Timestampable
{
	public function markCreated(\DateTimeImmutable $now): void;

	public function markUpdated(\DateTimeImmutable $now): void;

	public function getCreatedAt(): \DateTimeImmutable;

	public function getUpdatedAt(): \DateTimeImmutable;
}
