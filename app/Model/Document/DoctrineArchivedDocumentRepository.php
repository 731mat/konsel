<?php

declare(strict_types=1);

namespace App\Model\Document;

use App\Model\Meeting\Meeting;
use App\Model\Shared\Exception\NotFoundException;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineArchivedDocumentRepository implements ArchivedDocumentRepository
{
	public function __construct(
		private EntityManagerInterface $entityManager,
	) {}

	public function get(int $id): ArchivedDocument
	{
		return $this->entityManager->find(ArchivedDocument::class, $id)
			?? throw new NotFoundException('Dokument v archivu neexistuje.');
	}

	public function findByMeeting(Meeting $meeting): array
	{
		/** @var list<ArchivedDocument> */
		return $this->entityManager->createQueryBuilder()
			->select('d', 'u')
			->from(ArchivedDocument::class, 'd')
			->leftJoin('d.createdBy', 'u')
			->where('d.meeting = :meeting')
			->setParameter('meeting', $meeting)
			->orderBy('d.createdAt', 'DESC')
			->addOrderBy('d.id', 'DESC')
			->getQuery()
			->getResult();
	}

	public function add(ArchivedDocument $document): void
	{
		$this->entityManager->persist($document);
	}

	public function remove(ArchivedDocument $document): void
	{
		$this->entityManager->remove($document);
	}
}
