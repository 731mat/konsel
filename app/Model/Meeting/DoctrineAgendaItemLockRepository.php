<?php

declare(strict_types=1);

namespace App\Model\Meeting;

use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineAgendaItemLockRepository implements AgendaItemLockRepository
{
	public function __construct(
		private EntityManagerInterface $entityManager,
	) {}

	public function findByItem(AgendaItem $item): ?AgendaItemLock
	{
		return $this->entityManager->getRepository(AgendaItemLock::class)->findOneBy([
			'agendaItem' => $item,
		]);
	}

	public function findByMeeting(Meeting $meeting): array
	{
		/** @var list<AgendaItemLock> */
		return $this->entityManager->createQueryBuilder()
			->select('l', 'u')
			->from(AgendaItemLock::class, 'l')
			->join('l.agendaItem', 'i')
			->join('l.user', 'u')
			->where('i.meeting = :meeting')
			->setParameter('meeting', $meeting)
			->getQuery()
			->getResult();
	}

	public function add(AgendaItemLock $lock): void
	{
		$this->entityManager->persist($lock);
	}

	public function remove(AgendaItemLock $lock): void
	{
		$this->entityManager->remove($lock);
	}

	/**
	 * Volá se z konzole (locks:cleanup), kde filtr obcí není aktivní – maže napříč obcemi.
	 */
	public function removeExpired(\DateTimeImmutable $now): int
	{
		return $this->entityManager->createQueryBuilder()
			->delete(AgendaItemLock::class, 'l')
			->where('l.expiresAt <= :now')
			->setParameter('now', $now)
			->getQuery()
			->execute();
	}
}
