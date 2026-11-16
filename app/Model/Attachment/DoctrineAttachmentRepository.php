<?php

declare(strict_types=1);

namespace App\Model\Attachment;

use App\Model\Meeting\AgendaItem;
use App\Model\Meeting\Meeting;
use App\Model\Shared\Exception\NotFoundException;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineAttachmentRepository implements AttachmentRepository
{
	public function __construct(
		private EntityManagerInterface $entityManager,
	) {}

	public function get(int $id): Attachment
	{
		return $this->entityManager->find(Attachment::class, $id) ?? throw new NotFoundException('Příloha neexistuje.');
	}

	public function findByAgendaItem(AgendaItem $item): array
	{
		return $this->entityManager->getRepository(Attachment::class)->findBy([
			'agendaItem' => $item,
		], [
			'id' => 'ASC',
		]);
	}

	public function findByMeeting(Meeting $meeting): array
	{
		return $this->entityManager->createQueryBuilder()
			->select('a')
			->from(Attachment::class, 'a')
			->join('a.agendaItem', 'i')
			->where('i.meeting = :meeting')
			->setParameter('meeting', $meeting)
			->orderBy('i.position')
			->addOrderBy('a.id')
			->getQuery()
			->getResult();
	}

	public function countByMeeting(Meeting $meeting): array
	{
		$rows = $this->entityManager->createQueryBuilder()
			->select('IDENTITY(a.agendaItem) AS item, COUNT(a.id) AS total')
			->from(Attachment::class, 'a')
			->join('a.agendaItem', 'i')
			->where('i.meeting = :meeting')
			->setParameter('meeting', $meeting)
			->groupBy('a.agendaItem')
			->getQuery()
			->getArrayResult();

		$counts = [];
		foreach ($rows as $row) {
			assert(is_array($row) && is_numeric($row['item']) && is_numeric($row['total']));
			$counts[(int) $row['item']] = (int) $row['total'];
		}

		return $counts;
	}

	public function add(Attachment $attachment): void
	{
		$this->entityManager->persist($attachment);
	}

	public function remove(Attachment $attachment): void
	{
		$this->entityManager->remove($attachment);
	}
}
