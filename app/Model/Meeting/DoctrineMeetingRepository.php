<?php

declare(strict_types=1);

namespace App\Model\Meeting;

use App\Model\Municipality\Municipality;
use App\Model\People\Body;
use App\Model\People\Person;
use App\Model\Shared\Exception\NotFoundException;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\QueryBuilder;

final readonly class DoctrineMeetingRepository implements MeetingRepository
{
	/**
	 * @var EntityRepository<Meeting>
	 */
	private EntityRepository $repository;

	public function __construct(
		private EntityManagerInterface $entityManager,
	) {
		$this->repository = $entityManager->getRepository(Meeting::class);
	}

	public function get(int $id): Meeting
	{
		return $this->repository->find($id) ?? throw new NotFoundException('Zasedání neexistuje.');
	}

	public function findByFilter(Municipality $municipality, MeetingFilter $filter): array
	{
		$query = $this->queryFor($municipality)->orderBy('m.scheduledAt', 'DESC');

		if ($filter->body !== null) {
			$query->andWhere('m.body = :body')->setParameter('body', $filter->body);
		}

		if ($filter->year !== null) {
			$query->andWhere('m.scheduledAt >= :from AND m.scheduledAt < :to')
				->setParameter('from', new \DateTimeImmutable("{$filter->year}-01-01 00:00:00"), Types::DATETIME_IMMUTABLE)
				->setParameter('to', new \DateTimeImmutable(($filter->year + 1) . '-01-01 00:00:00'), Types::DATETIME_IMMUTABLE);
		}

		if ($filter->status !== null) {
			$query->andWhere('m.status = :status')->setParameter('status', $filter->status->value);
		}

		return $this->result($query);
	}

	public function findUnfinished(Municipality $municipality, int $limit, ?Body $body = null): array
	{
		return $this->findByStatuses($municipality, [MeetingStatus::Draft, MeetingStatus::Shared, MeetingStatus::InProgress], 'ASC', $limit, $body);
	}

	public function findFinished(Municipality $municipality, int $limit, ?Body $body = null): array
	{
		return $this->findByStatuses($municipality, [MeetingStatus::Closed, MeetingStatus::MinutesApproved], 'DESC', $limit, $body);
	}

	public function findYears(Municipality $municipality): array
	{
		$dates = $this->entityManager->createQueryBuilder()
			->select('m.scheduledAt')
			->from(Meeting::class, 'm')
			->where('m.municipality = :municipality')
			->setParameter('municipality', $municipality)
			->getQuery()
			->getSingleColumnResult();

		$years = [];
		foreach ($dates as $date) {
			$year = match (true) {
				$date instanceof \DateTimeInterface => (int) $date->format('Y'),
				is_string($date) => (int) substr($date, 0, 4),
				default => throw new \UnexpectedValueException('Unexpected scheduledAt value.'),
			};
			$years[$year] = $year;
		}

		rsort($years);

		return $years;
	}

	public function findByBodyAndNumber(Body $body, int $number): ?Meeting
	{
		return $this->repository->findOneBy([
			'body' => $body,
			'number' => $number,
		]);
	}

	public function findConstituent(Body $body): ?Meeting
	{
		return $this->repository->findOneBy([
			'body' => $body,
			'type' => MeetingType::Constituent,
		]);
	}

	public function findLast(Municipality $municipality): ?Meeting
	{
		return $this->repository->findOneBy([
			'municipality' => $municipality,
		], [
			'scheduledAt' => 'DESC',
		]);
	}

	public function getMaxNumber(Body $body): int
	{
		$max = $this->entityManager->createQueryBuilder()
			->select('MAX(m.number)')
			->from(Meeting::class, 'm')
			->where('m.body = :body')
			->setParameter('body', $body)
			->getQuery()
			->getSingleScalarResult();

		return is_numeric($max) ? (int) $max : 0;
	}

	public function hasOfficial(Person $person): bool
	{
		$count = $this->entityManager->createQueryBuilder()
			->select('COUNT(m.id)')
			->from(Meeting::class, 'm')
			->where('m.chairperson = :person OR m.recorder = :person OR m.firstVerifier = :person OR m.secondVerifier = :person')
			->setParameter('person', $person)
			->getQuery()
			->getSingleScalarResult();

		return is_numeric($count) && (int) $count > 0;
	}

	public function add(Meeting $meeting): void
	{
		$this->entityManager->persist($meeting);
	}

	public function remove(Meeting $meeting): void
	{
		$this->entityManager->remove($meeting);
	}

	/**
	 * @param list<MeetingStatus> $statuses
	 * @param 'ASC'|'DESC' $direction
	 * @return list<Meeting>
	 */
	private function findByStatuses(Municipality $municipality, array $statuses, string $direction, int $limit, ?Body $body = null): array
	{
		$query = $this->queryFor($municipality)
			->andWhere('m.status IN (:statuses)')
			->setParameter('statuses', array_map(static fn(MeetingStatus $status): string => $status->value, $statuses), ArrayParameterType::STRING)
			->orderBy('m.scheduledAt', $direction)
			->setMaxResults($limit);
		if ($body !== null) {
			$query->andWhere('m.body = :body')->setParameter('body', $body);
		}

		return $this->result($query);
	}

	private function queryFor(Municipality $municipality): QueryBuilder
	{
		return $this->repository->createQueryBuilder('m')
			->addSelect('b')
			->join('m.body', 'b')
			->where('m.municipality = :municipality')
			->setParameter('municipality', $municipality);
	}

	/**
	 * @return list<Meeting>
	 */
	private function result(QueryBuilder $query): array
	{
		$rows = $query->getQuery()->getResult();
		assert(is_array($rows));
		$result = [];
		foreach ($rows as $meeting) {
			assert($meeting instanceof Meeting);
			$result[] = $meeting;
		}

		return $result;
	}
}
