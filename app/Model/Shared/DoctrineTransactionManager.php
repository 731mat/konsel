<?php

declare(strict_types=1);

namespace App\Model\Shared;

use App\Model\Shared\Exception\DomainException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Transakce use-case: flush + commit, při chybě rollback.
 *
 * Rozdíl proti EntityManager::wrapInTransaction(): při DomainException (očekávaná chyba – validace,
 * neplatný stav) EntityManager NEZAVÍRÁ a vrátí rozpracované změny entit v paměti, aby request
 * mohl dál číst z DB (např. vykreslit formulář s chybou). Jiné výjimky EntityManager zavřou.
 */
final readonly class DoctrineTransactionManager implements TransactionManager
{
	public function __construct(
		private EntityManagerInterface $entityManager,
	) {}

	public function run(callable $operation): mixed
	{
		$connection = $this->entityManager->getConnection();
		$connection->beginTransaction();

		try {
			$result = $operation();
			$this->entityManager->flush();
			$connection->commit();

			return $result;
		} catch (\Throwable $e) {
			if ($connection->isTransactionActive()) {
				$connection->rollBack();
			}

			if ($e instanceof DomainException) {
				$this->revertPendingChanges();
			} else {
				$this->entityManager->close();
			}

			throw $e;
		}
	}

	private function revertPendingChanges(): void
	{
		$unitOfWork = $this->entityManager->getUnitOfWork();
		$unitOfWork->computeChangeSets();

		foreach ($unitOfWork->getScheduledEntityInsertions() as $entity) {
			$this->entityManager->detach($entity);
		}

		foreach ($unitOfWork->getScheduledEntityUpdates() as $entity) {
			$this->entityManager->refresh($entity);
		}
	}
}
