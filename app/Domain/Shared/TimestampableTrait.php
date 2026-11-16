<?php

declare(strict_types=1);

namespace App\Domain\Shared;

use Doctrine\ORM\Mapping as ORM;

/**
 * @phpstan-require-implements Timestampable
 */
trait TimestampableTrait
{
	#[ORM\Column]
	private \DateTimeImmutable $createdAt;

	#[ORM\Column]
	private \DateTimeImmutable $updatedAt;

	public function markCreated(\DateTimeImmutable $now): void
	{
		$this->createdAt = $now;
		$this->updatedAt = $now;
	}

	public function markUpdated(\DateTimeImmutable $now): void
	{
		$this->updatedAt = $now;
	}

	public function getCreatedAt(): \DateTimeImmutable
	{
		return $this->createdAt;
	}

	public function getUpdatedAt(): \DateTimeImmutable
	{
		return $this->updatedAt;
	}
}
