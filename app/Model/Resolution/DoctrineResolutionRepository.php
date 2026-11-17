<?php

declare(strict_types=1);

namespace App\Model\Resolution;

use App\Model\Meeting\Meeting;
use App\Model\Meeting\VotingRound;
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

	public function add(Resolution $resolution): void
	{
		$this->entityManager->persist($resolution);
	}

	public function remove(Resolution $resolution): void
	{
		$this->entityManager->remove($resolution);
	}
}
