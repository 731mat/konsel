<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Application\Shared\TransactionManager;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineTransactionManager implements TransactionManager
{
	public function __construct(
		private EntityManagerInterface $entityManager,
	) {}

	public function run(callable $operation): mixed
	{
		return $this->entityManager->wrapInTransaction(static fn(): mixed => $operation());
	}
}
