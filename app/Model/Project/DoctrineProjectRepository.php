<?php

declare(strict_types=1);

namespace App\Model\Project;

use App\Model\Municipality\Municipality;
use App\Model\Shared\Exception\NotFoundException;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineProjectRepository implements ProjectRepository
{
	public function __construct(
		private EntityManagerInterface $entityManager,
	) {}

	public function get(int $id): Project
	{
		return $this->entityManager->find(Project::class, $id) ?? throw new NotFoundException('Úkol neexistuje.');
	}

	public function findByMunicipality(Municipality $municipality, ?ProjectStatus $status = null, bool $onlyActive = false): array
	{
		$criteria = [
			'municipality' => $municipality,
		];
		if ($status !== null) {
			$criteria['status'] = $status;
		}

		$projects = $this->entityManager->getRepository(Project::class)->findBy($criteria, [
			'updatedAt' => 'DESC',
		]);
		if ($onlyActive) {
			$projects = array_values(array_filter($projects, static fn(Project $p): bool => $p->getStatus()->isActive()));
		}

		usort($projects, static fn(Project $a, Project $b): int => [!$a->getStatus()->isActive(), $a->getPriority()->rank()] <=> [!$b->getStatus()->isActive(), $b->getPriority()->rank()]);

		return $projects;
	}

	public function findPublic(Municipality $municipality): array
	{
		return $this->findPublicSorted($municipality);
	}

	public function countByStatus(Municipality $municipality): array
	{
		$rows = $this->entityManager->createQueryBuilder()
			->select('p.status AS status, COUNT(p.id) AS total')
			->from(Project::class, 'p')
			->where('p.municipality = :municipality')
			->setParameter('municipality', $municipality)
			->groupBy('p.status')
			->getQuery()
			->getArrayResult();

		$counts = [];
		foreach ($rows as $row) {
			assert(is_array($row) && is_numeric($row['total']));
			$status = match (true) {
				$row['status'] instanceof ProjectStatus => $row['status']->value,
				is_string($row['status']) => $row['status'],
				default => throw new \UnexpectedValueException('Unexpected project status.'),
			};
			$counts[$status] = (int) $row['total'];
		}

		return $counts;
	}

	public function add(Project $project): void
	{
		$this->entityManager->persist($project);
	}

	/**
	 * @return list<Project>
	 */
	private function findPublicSorted(Municipality $municipality): array
	{
		$projects = $this->entityManager->getRepository(Project::class)->findBy([
			'municipality' => $municipality,
			'public' => true,
		], [
			'updatedAt' => 'DESC',
		]);
		usort($projects, static fn(Project $a, Project $b): int => [!$a->getStatus()->isActive(), $a->getPriority()->rank()] <=> [!$b->getStatus()->isActive(), $b->getPriority()->rank()]);

		return $projects;
	}
}
