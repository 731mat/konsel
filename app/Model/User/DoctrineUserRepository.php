<?php

declare(strict_types=1);

namespace App\Model\User;

use App\Model\Municipality\Municipality;
use App\Model\Shared\Exception\NotFoundException;
use App\Model\Shared\ValueObject\Email;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;

final readonly class DoctrineUserRepository implements UserRepository
{
	/**
	 * @var EntityRepository<User>
	 */
	private EntityRepository $repository;

	public function __construct(
		private EntityManagerInterface $entityManager,
	) {
		$this->repository = $entityManager->getRepository(User::class);
	}

	public function get(int $id): User
	{
		return $this->find($id) ?? throw new NotFoundException('Uživatel neexistuje.');
	}

	public function find(int $id): ?User
	{
		return $this->repository->find($id);
	}

	public function findByEmail(Email $email): ?User
	{
		return $this->repository->findOneBy([
			'email' => $email->value,
		]);
	}

	public function findByMunicipality(Municipality $municipality): array
	{
		return $this->repository->findBy([
			'municipality' => $municipality,
		], [
			'name' => 'ASC',
		]);
	}

	public function countActiveAdmins(Municipality $municipality): int
	{
		return $this->repository->count([
			'municipality' => $municipality,
			'role' => Role::Admin,
			'active' => true,
		]);
	}

	public function add(User $user): void
	{
		$this->entityManager->persist($user);
	}
}
