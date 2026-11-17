<?php

declare(strict_types=1);

namespace App\Model\Ai;

use App\Model\Municipality\Municipality;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineAiUsageLogRepository implements AiUsageLogRepository
{
	public function __construct(
		private EntityManagerInterface $entityManager,
	) {}

	public function add(AiUsageLog $log): void
	{
		$this->entityManager->persist($log);
	}

	public function summarize(Municipality $municipality, \DateTimeImmutable $since): array
	{
		$row = $this->entityManager->createQueryBuilder()
			->select('COUNT(l.id) AS calls', 'COALESCE(SUM(l.promptTokens + l.completionTokens), 0) AS tokens')
			->from(AiUsageLog::class, 'l')
			->where('l.municipality = :municipality')
			->andWhere('l.createdAt >= :since')
			->setParameter('municipality', $municipality)
			->setParameter('since', $since)
			->getQuery()
			->getSingleResult();

		return [
			'count' => is_array($row) && is_numeric($row['calls'] ?? null) ? (int) $row['calls'] : 0,
			'tokens' => is_array($row) && is_numeric($row['tokens'] ?? null) ? (int) $row['tokens'] : 0,
		];
	}
}
