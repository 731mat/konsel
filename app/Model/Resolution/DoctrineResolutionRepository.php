<?php

declare(strict_types=1);

namespace App\Model\Resolution;

use App\Model\Meeting\Meeting;
use App\Model\Meeting\VotingRound;
use App\Model\Municipality\Municipality;
use App\Model\Shared\Exception\NotFoundException;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineResolutionRepository implements ResolutionRepository
{
	public function __construct(
		private EntityManagerInterface $entityManager,
	) {}

	public function get(int $id): Resolution
	{
		return $this->entityManager->find(Resolution::class, $id) ?? throw new NotFoundException('Usnesení neexistuje.');
	}

	public function findByVotingRound(VotingRound $round): ?Resolution
	{
		return $this->entityManager->getRepository(Resolution::class)->findOneBy([
			'votingRound' => $round,
		]);
	}

	public function findByMeeting(Meeting $meeting): array
	{
		/** @var list<Resolution> */
		return $this->entityManager->createQueryBuilder()
			->select('r', 'i')
			->from(Resolution::class, 'r')
			->join('r.agendaItem', 'i')
			->where('r.meeting = :meeting')
			->setParameter('meeting', $meeting)
			->orderBy('r.sequence')
			->addOrderBy('i.position')
			->getQuery()
			->getResult();
	}

	public function search(Municipality $municipality, ResolutionSearch $search): array
	{
		$query = $this->entityManager->createQueryBuilder()
			->select('r', 'm', 'i', 'b')
			->from(Resolution::class, 'r')
			->join('r.meeting', 'm')
			->join('r.agendaItem', 'i')
			->join('r.body', 'b')
			->where('r.municipality = :municipality')
			->setParameter('municipality', $municipality)
			->orderBy('r.adoptedAt', 'DESC')
			->addOrderBy('r.sequence', 'DESC');

		if ($search->bodyId !== null) {
			$query->andWhere('b.id = :body')->setParameter('body', $search->bodyId);
		}

		if ($search->year !== null) {
			$query->andWhere('m.scheduledAt >= :from AND m.scheduledAt < :to')
				->setParameter('from', new \DateTimeImmutable("{$search->year}-01-01"))
				->setParameter('to', new \DateTimeImmutable(($search->year + 1) . '-01-01'));
		}

		if ($search->status !== null) {
			$query->andWhere('r.status = :status')->setParameter('status', $search->status);
		}

		$text = trim((string) $search->text);
		if ($text !== '') {
			$query->andWhere('LOWER(r.text) LIKE :text OR LOWER(r.number) LIKE :text OR LOWER(i.title) LIKE :text')
				->setParameter('text', '%' . addcslashes(mb_strtolower($text), '%_') . '%');
		}

		/** @var list<Resolution> */
		return $query->getQuery()->getResult();
	}

	public function findYears(Municipality $municipality): array
	{
		$years = [];
		foreach ($this->search($municipality, new ResolutionSearch()) as $resolution) {
			$years[(int) $resolution->getMeeting()->getScheduledAt()->format('Y')] = true;
		}

		$years = array_keys($years);
		rsort($years);

		return $years;
	}

	public function add(Resolution $resolution): void
	{
		$this->entityManager->persist($resolution);
	}

	public function remove(Resolution $resolution): void
	{
		$this->entityManager->remove($resolution);
	}
}
