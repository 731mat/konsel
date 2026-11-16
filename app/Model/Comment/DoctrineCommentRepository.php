<?php

declare(strict_types=1);

namespace App\Model\Comment;

use App\Model\Meeting\AgendaItem;
use App\Model\Meeting\Meeting;
use App\Model\Project\Project;
use App\Model\Shared\Exception\NotFoundException;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineCommentRepository implements CommentRepository
{
	public function __construct(
		private EntityManagerInterface $entityManager,
	) {}

	public function get(int $id): Comment
	{
		return $this->entityManager->find(Comment::class, $id) ?? throw new NotFoundException('Komentář neexistuje.');
	}

	public function findByAgendaItem(AgendaItem $item): array
	{
		return $this->entityManager->getRepository(Comment::class)->findBy([
			'agendaItem' => $item,
		], [
			'createdAt' => 'ASC',
			'id' => 'ASC',
		]);
	}

	public function findByProject(Project $project): array
	{
		return $this->entityManager->getRepository(Comment::class)->findBy([
			'project' => $project,
		], [
			'createdAt' => 'ASC',
			'id' => 'ASC',
		]);
	}

	public function countByMeeting(Meeting $meeting): array
	{
		$rows = $this->entityManager->createQueryBuilder()
			->select('IDENTITY(c.agendaItem) AS item, COUNT(c.id) AS total')
			->from(Comment::class, 'c')
			->join('c.agendaItem', 'i')
			->where('i.meeting = :meeting')
			->andWhere('c.deletedAt IS NULL')
			->setParameter('meeting', $meeting)
			->groupBy('c.agendaItem')
			->getQuery()
			->getArrayResult();

		$counts = [];
		foreach ($rows as $row) {
			assert(is_array($row) && is_numeric($row['item']) && is_numeric($row['total']));
			$counts[(int) $row['item']] = (int) $row['total'];
		}

		return $counts;
	}

	public function add(Comment $comment): void
	{
		$this->entityManager->persist($comment);
	}
}
