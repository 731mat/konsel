<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Repository;

use App\Domain\People\Body;
use App\Domain\People\BodyRepository;
use App\Domain\People\BodyType;
use App\Domain\People\Term;
use App\Domain\Shared\Exception\NotFoundException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;

final readonly class DoctrineBodyRepository implements BodyRepository
{
	/**
	 * @var EntityRepository<Body>
	 */
	private EntityRepository $repository;

	public function __construct(
		private EntityManagerInterface $entityManager,
	) {
		$this->repository = $entityManager->getRepository(Body::class);
	}

	public function get(int $id): Body
	{
		return $this->repository->find($id) ?? throw new NotFoundException('Orgán neexistuje.');
	}

	public function findByTerm(Term $term): array
	{
		$bodies = $this->repository->findBy([
			'term' => $term,
		], [
			'name' => 'ASC',
		]);
		$order = [
			BodyType::Council->value => 0,
			BodyType::Board->value => 1,
			BodyType::Committee->value => 2,
		];
		usort($bodies, static fn(Body $a, Body $b): int => [$order[$a->getType()->value], $a->getName()] <=> [$order[$b->getType()->value], $b->getName()]);

		return $bodies;
	}

	public function findByTermAndType(Term $term, BodyType $type): ?Body
	{
		return $this->repository->findOneBy([
			'term' => $term,
			'type' => $type,
		]);
	}

	public function add(Body $body): void
	{
		$this->entityManager->persist($body);
	}

	public function remove(Body $body): void
	{
		$this->entityManager->remove($body);
	}
}
