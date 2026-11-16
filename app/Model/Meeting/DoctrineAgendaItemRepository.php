<?php

declare(strict_types=1);

namespace App\Model\Meeting;

use App\Model\Municipality\Municipality;
use App\Model\Project\Project;
use App\Model\Shared\Exception\NotFoundException;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineAgendaItemRepository implements AgendaItemRepository
{
	public function __construct(
		private EntityManagerInterface $entityManager,
	) {}

	public function get(int $id): AgendaItem
	{
		return $this->entityManager->find(AgendaItem::class, $id) ?? throw new NotFoundException('Bod programu neexistuje.');
	}

	public function findByProject(Project $project): array
	{
		return $this->entityManager->createQueryBuilder()
			->select('i', 'm')
			->from(AgendaItem::class, 'i')
			->join('i.meeting', 'm')
			->where('i.project = :project')
			->setParameter('project', $project)
			->orderBy('m.scheduledAt')
			->addOrderBy('i.position')
			->getQuery()
			->getResult();
	}

	public function findRecent(Municipality $municipality, \DateTimeImmutable $since): array
	{
		return $this->entityManager->createQueryBuilder()
			->select('i', 'm')
			->from(AgendaItem::class, 'i')
			->join('i.meeting', 'm')
			->where('i.municipality = :municipality')
			->andWhere('m.scheduledAt >= :since')
			->setParameter('municipality', $municipality)
			->setParameter('since', $since, \Doctrine\DBAL\Types\Types::DATETIME_IMMUTABLE)
			->orderBy('m.scheduledAt', 'DESC')
			->addOrderBy('i.position')
			->getQuery()
			->getResult();
	}
}
