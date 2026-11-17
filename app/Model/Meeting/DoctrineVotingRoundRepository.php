<?php

declare(strict_types=1);

namespace App\Model\Meeting;

use App\Model\Shared\Exception\NotFoundException;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineVotingRoundRepository implements VotingRoundRepository
{
	public function __construct(
		private EntityManagerInterface $entityManager,
	) {}

	public function get(int $id): VotingRound
	{
		return $this->entityManager->find(VotingRound::class, $id) ?? throw new NotFoundException('Hlasování neexistuje.');
	}

	public function findByItem(AgendaItem $item): array
	{
		return $this->entityManager->getRepository(VotingRound::class)->findBy([
			'agendaItem' => $item,
		], [
			'sequence' => 'ASC',
		]);
	}

	public function findByMeeting(Meeting $meeting): array
	{
		/** @var list<VotingRound> */
		return $this->entityManager->createQueryBuilder()
			->select('r', 'i')
			->from(VotingRound::class, 'r')
			->join('r.agendaItem', 'i')
			->where('r.meeting = :meeting')
			->setParameter('meeting', $meeting)
			->orderBy('i.position')
			->addOrderBy('r.sequence')
			->getQuery()
			->getResult();
	}

	public function add(VotingRound $round): void
	{
		$this->entityManager->persist($round);
	}

	public function remove(VotingRound $round): void
	{
		$this->entityManager->remove($round);
	}
}
