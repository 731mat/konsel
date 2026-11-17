<?php

declare(strict_types=1);

namespace App\Model\Search;

use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineSearchSource implements SearchSource
{
	public function __construct(
		private EntityManagerInterface $entityManager,
	) {}

	public function all(): iterable
	{
		foreach (SearchDocumentFactory::IndexedClasses as $class) {
			foreach ($this->entityManager->createQueryBuilder()->select('e')->from($class, 'e')->getQuery()->toIterable() as $entity) {
				if (is_object($entity)) {
					yield $entity;
				}
			}
		}
	}
}
