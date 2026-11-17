<?php

declare(strict_types=1);

namespace App\Model\Mail;

use App\Model\Meeting\Meeting;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineMailLogRepository implements MailLogRepository
{
	public function __construct(
		private EntityManagerInterface $entityManager,
	) {}

	public function add(MailLog $log): void
	{
		$this->entityManager->persist($log);
	}

	public function findByMeeting(Meeting $meeting): array
	{
		return $this->entityManager->getRepository(MailLog::class)->findBy([
			'meeting' => $meeting,
		], [
			'sentAt' => 'DESC',
			'id' => 'DESC',
		]);
	}
}
