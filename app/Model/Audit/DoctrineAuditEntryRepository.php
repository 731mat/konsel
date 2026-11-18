<?php

declare(strict_types=1);

namespace App\Model\Audit;

use App\Model\Municipality\Municipality;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;

final readonly class DoctrineAuditEntryRepository implements AuditEntryRepository
{
	public function __construct(
		private EntityManagerInterface $entityManager,
	) {}

	public function find(Municipality $municipality, AuditFilter $filter, int $limit, int $offset): array
	{
		$query = $this->base($municipality);
		if ($filter->type !== null) {
			$query->andWhere('a.entityType = :type')->setParameter('type', $filter->type);
		}

		if ($filter->actorUserId !== null) {
			$query->andWhere('IDENTITY(a.actorUser) = :actor')->setParameter('actor', $filter->actorUserId);
		}

		if ($filter->since !== null) {
			$query->andWhere('a.createdAt >= :since')->setParameter('since', $filter->since);
		}

		if ($filter->text !== null && trim($filter->text) !== '') {
			$query->andWhere('LOWER(a.entityLabel) LIKE :text')->setParameter('text', '%' . mb_strtolower(trim($filter->text)) . '%');
		}

		$total = (int) (clone $query)->select('COUNT(a.id)')->resetDQLPart('orderBy')->getQuery()->getSingleScalarResult();

		/** @var list<AuditEntry> $entries */
		$entries = $query->setMaxResults($limit)->setFirstResult($offset)->getQuery()->getResult();

		return [$entries, $total];
	}

	public function findByMeeting(Municipality $municipality, int $meetingId): array
	{
		/** @var list<AuditEntry> */
		return $this->base($municipality)
			->andWhere('a.meetingId = :meeting')
			->setParameter('meeting', $meetingId)
			->setMaxResults(500)
			->getQuery()
			->getResult();
	}

	public function findFor(Municipality $municipality, array $entities, array $children): array
	{
		$query = $this->base($municipality);
		$conditions = [];
		foreach ([...array_map(static fn(array $e): array => [...$e, 'entityId'], $entities), ...array_map(static fn(array $e): array => [...$e, 'relatedId'], $children)] as $i => [$type, $id, $column]) {
			$conditions[] = "(a.entityType = :type{$i} AND a.{$column} = :id{$i})";
			$query->setParameter("type{$i}", $type)->setParameter("id{$i}", $id);
		}

		if ($conditions === []) {
			return [];
		}

		/** @var list<AuditEntry> */
		return $query->andWhere(implode(' OR ', $conditions))->setMaxResults(500)->getQuery()->getResult();
	}

	public function findActors(Municipality $municipality): array
	{
		$rows = $this->entityManager->createQueryBuilder()
			->select('IDENTITY(a.actorUser) AS id', 'MAX(a.actorName) AS name')
			->from(AuditEntry::class, 'a')
			->where('a.municipality = :municipality')
			->andWhere('a.actorUser IS NOT NULL')
			->setParameter('municipality', $municipality)
			->groupBy('a.actorUser')
			->orderBy('name')
			->getQuery()
			->getArrayResult();

		$actors = [];
		foreach ($rows as $row) {
			if (is_array($row) && is_numeric($row['id'] ?? null) && is_string($row['name'] ?? null)) {
				$actors[(int) $row['id']] = $row['name'];
			}
		}

		return $actors;
	}

	private function base(Municipality $municipality): QueryBuilder
	{
		return $this->entityManager->createQueryBuilder()
			->select('a')
			->from(AuditEntry::class, 'a')
			->where('a.municipality = :municipality')
			->setParameter('municipality', $municipality)
			->orderBy('a.createdAt', 'DESC')
			->addOrderBy('a.id', 'DESC');
	}
}
