<?php

declare(strict_types=1);

namespace App\Model\User;

use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineLoginAttemptRepository implements LoginAttemptRepository
{
	public function __construct(
		private EntityManagerInterface $entityManager,
	) {}

	public function add(LoginAttempt $attempt): void
	{
		$this->entityManager->persist($attempt);
	}

	public function countRecentFailuresForEmail(string $email, \DateTimeImmutable $since): int
	{
		$lastSuccess = $this->entityManager->createQueryBuilder()
			->select('MAX(a.attemptedAt)')
			->from(LoginAttempt::class, 'a')
			->where('a.email = :email AND a.successful = true')
			->setParameter('email', $email)
			->getQuery()
			->getSingleScalarResult();

		if (is_string($lastSuccess)) {
			$lastSuccess = new \DateTimeImmutable($lastSuccess);
			$since = max($since, $lastSuccess);
		}

		return $this->countFailures('email', $email, $since);
	}

	public function countRecentFailuresForIp(string $ip, \DateTimeImmutable $since): int
	{
		return $this->countFailures('ip', $ip, $since);
	}

	private function countFailures(string $field, string $value, \DateTimeImmutable $since): int
	{
		$count = $this->entityManager->createQueryBuilder()
			->select('COUNT(a.id)')
			->from(LoginAttempt::class, 'a')
			->where("a.{$field} = :value AND a.successful = false AND a.attemptedAt > :since")
			->setParameter('value', $value)
			->setParameter('since', $since)
			->getQuery()
			->getSingleScalarResult();

		return is_numeric($count) ? (int) $count : 0;
	}
}
