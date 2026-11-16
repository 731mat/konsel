<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Repository;

use App\Domain\Municipality\Municipality;
use App\Domain\People\Term;
use App\Domain\People\TermRepository;
use App\Domain\Shared\Exception\NotFoundException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;

final readonly class DoctrineTermRepository implements TermRepository
{
	/**
	 * @var EntityRepository<Term>
	 */
	private EntityRepository $repository;

	public function __construct(
		private EntityManagerInterface $entityManager,
	) {
		$this->repository = $entityManager->getRepository(Term::class);
	}

	public function get(int $id): Term
	{
		return $this->repository->find($id) ?? throw new NotFoundException('Volební období neexistuje.');
	}

	public function findByMunicipality(Municipality $municipality): array
	{
		return $this->repository->findBy([
			'municipality' => $municipality,
		], [
			'startsOn' => 'DESC',
		]);
	}

	public function findAt(Municipality $municipality, \DateTimeImmutable $date): ?Term
	{
		foreach ($this->findByMunicipality($municipality) as $term) {
			if ($term->contains($date)) {
				return $term;
			}
		}

		return null;
	}

	public function add(Term $term): void
	{
		$this->entityManager->persist($term);
	}
}
