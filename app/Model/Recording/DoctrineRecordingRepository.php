<?php

declare(strict_types=1);

namespace App\Model\Recording;

use App\Model\Meeting\Meeting;
use App\Model\Shared\Exception\NotFoundException;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineRecordingRepository implements RecordingRepository
{
	public function __construct(
		private EntityManagerInterface $entityManager,
	) {}

	public function get(int $id): Recording
	{
		return $this->entityManager->find(Recording::class, $id) ?? throw new NotFoundException('Záznam neexistuje.');
	}

	public function findByMeeting(Meeting $meeting): array
	{
		/** @var list<Recording> */
		return $this->entityManager->getRepository(Recording::class)->findBy([
			'meeting' => $meeting,
		], [
			'id' => 'ASC',
		]);
	}

	public function add(Recording $recording): void
	{
		$this->entityManager->persist($recording);
	}

	public function remove(Recording $recording): void
	{
		$this->entityManager->remove($recording);
	}
}
