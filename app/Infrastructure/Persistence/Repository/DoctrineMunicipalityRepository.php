<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Repository;

use App\Domain\Municipality\Municipality;
use App\Domain\Municipality\MunicipalityRepository;
use App\Domain\Shared\Exception\NotFoundException;
use App\Domain\Shared\ValueObject\Ico;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;

final readonly class DoctrineMunicipalityRepository implements MunicipalityRepository
{
	/**
	 * @var EntityRepository<Municipality>
	 */
	private EntityRepository $repository;

	public function __construct(
		private EntityManagerInterface $entityManager,
	) {
		$this->repository = $entityManager->getRepository(Municipality::class);
	}

	public function get(int $id): Municipality
	{
		return $this->repository->find($id) ?? throw new NotFoundException('Obec neexistuje.');
	}

	public function findBySlug(string $slug): ?Municipality
	{
		return $this->repository->findOneBy([
			'slug' => $slug,
		]);
	}

	public function findByIco(Ico $ico): ?Municipality
	{
		return $this->repository->findOneBy([
			'ico' => $ico->value,
		]);
	}

	public function findAll(): array
	{
		return $this->repository->findBy([], [
			'name' => 'ASC',
		]);
	}

	public function add(Municipality $municipality): void
	{
		$this->entityManager->persist($municipality);
	}
}
