<?php

declare(strict_types=1);

namespace App\Model\Resolution;

use App\Model\Municipality\Municipality;
use App\Model\Shared\Exception\NotFoundException;
use App\Model\Shared\ValueObject\Email;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineResolutionTaskRepository implements ResolutionTaskRepository
{
	public function __construct(
		private EntityManagerInterface $entityManager,
	) {}

	public function get(int $id): ResolutionTask
	{
		return $this->entityManager->find(ResolutionTask::class, $id) ?? throw new NotFoundException('Úkol neexistuje.');
	}

	public function findByResolution(Resolution $resolution): array
	{
		return $this->entityManager->getRepository(ResolutionTask::class)->findBy([
			'resolution' => $resolution,
		], [
			'createdAt' => 'ASC',
			'id' => 'ASC',
		]);
	}

	public function find(Municipality $municipality, TaskFilter $filter, \DateTimeImmutable $today, ?Email $assigneeEmail = null): array
	{
		$query = $this->entityManager->createQueryBuilder()
			->select('t', 'r', 'a')
			->from(ResolutionTask::class, 't')
			->join('t.resolution', 'r')
			->leftJoin('t.assignee', 'a')
			->where('t.municipality = :municipality')
			->setParameter('municipality', $municipality)
			->addSelect('CASE WHEN t.dueDate IS NULL THEN 1 ELSE 0 END AS HIDDEN noDue')
			->orderBy('noDue')
			->addOrderBy('t.dueDate')
			->addOrderBy('t.id');

		if ($filter !== TaskFilter::All) {
			$query->andWhere('t.status = :open')->setParameter('open', TaskStatus::Open);
		}

		if ($filter === TaskFilter::Overdue) {
			$query->andWhere('t.dueDate < :today')->setParameter('today', $today->setTime(0, 0), 'date_immutable');
		}

		if ($filter === TaskFilter::Mine) {
			$query->andWhere('a.email = :email')->setParameter('email', $assigneeEmail === null ? '' : $assigneeEmail->value);
		}

		/** @var list<ResolutionTask> */
		return $query->getQuery()->getResult();
	}

	public function add(ResolutionTask $task): void
	{
		$this->entityManager->persist($task);
	}

	public function remove(ResolutionTask $task): void
	{
		$this->entityManager->remove($task);
	}
}
