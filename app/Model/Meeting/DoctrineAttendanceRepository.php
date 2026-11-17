<?php

declare(strict_types=1);

namespace App\Model\Meeting;

use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineAttendanceRepository implements AttendanceRepository
{
	public function __construct(
		private EntityManagerInterface $entityManager,
	) {}

	public function findByMeeting(Meeting $meeting): array
	{
		return $this->entityManager->getRepository(Attendance::class)->findBy([
			'meeting' => $meeting,
		]);
	}

	public function add(Attendance $attendance): void
	{
		$this->entityManager->persist($attendance);
	}
}
