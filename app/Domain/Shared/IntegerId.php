<?php

declare(strict_types=1);

namespace App\Domain\Shared;

use Doctrine\ORM\Mapping as ORM;

/**
 * Autoinkrementální celočíselný primární klíč.
 */
trait IntegerId
{
	#[ORM\Id]
	#[ORM\GeneratedValue]
	#[ORM\Column]
	private ?int $id = null;

	public function getId(): int
	{
		return $this->id ?? throw new \LogicException(sprintf('Entity %s has not been persisted yet.', static::class));
	}

	public function isPersisted(): bool
	{
		return $this->id !== null;
	}
}
