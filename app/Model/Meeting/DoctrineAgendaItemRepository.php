<?php

declare(strict_types=1);

namespace App\Model\Meeting;

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
}
