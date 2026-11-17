<?php

declare(strict_types=1);

namespace App\Model\People;

use App\Model\Municipality\Municipality;
use App\Model\Shared\CzechCollation;
use App\Model\Shared\Exception\NotFoundException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;

final readonly class DoctrinePersonRepository implements PersonRepository
{
	/**
	 * @var EntityRepository<Person>
	 */
	private EntityRepository $repository;

	public function __construct(
		private EntityManagerInterface $entityManager,
	) {
		$this->repository = $entityManager->getRepository(Person::class);
	}

	public function get(int $id): Person
	{
		return $this->repository->find($id) ?? throw new NotFoundException('Osoba neexistuje.');
	}

	public function findByMunicipality(Municipality $municipality): array
	{
		$persons = $this->repository->findBy([
			'municipality' => $municipality,
		]);
		usort($persons, static fn(Person $a, Person $b): int => CzechCollation::compare($a->getName()->sortable(), $b->getName()->sortable()));

		return $persons;
	}

	public function hasMemberships(Person $person): bool
	{
		return $this->entityManager->getRepository(Membership::class)->count([
			'person' => $person,
		]) > 0;
	}

	public function add(Person $person): void
	{
		$this->entityManager->persist($person);
	}

	public function remove(Person $person): void
	{
		$this->entityManager->remove($person);
	}
}
