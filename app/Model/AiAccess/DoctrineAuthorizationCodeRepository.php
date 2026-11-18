<?php

declare(strict_types=1);

namespace App\Model\AiAccess;

use App\Core\Tenancy\TenantFilterSuspender;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineAuthorizationCodeRepository implements AuthorizationCodeRepository
{
	public function __construct(
		private EntityManagerInterface $entityManager,
	) {}

	public function findByHash(string $hash): ?AuthorizationCode
	{
		return TenantFilterSuspender::run($this->entityManager, fn(): ?AuthorizationCode => $this->entityManager->getRepository(AuthorizationCode::class)->findOneBy([
			'codeHash' => $hash,
		]));
	}

	public function add(AuthorizationCode $code): void
	{
		$this->entityManager->persist($code);
	}

	public function deleteExpired(\DateTimeImmutable $before): int
	{
		return TenantFilterSuspender::run($this->entityManager, fn(): int => $this->entityManager->createQuery(
			'DELETE FROM ' . AuthorizationCode::class . ' a WHERE a.expiresAt < :before',
		)->setParameter('before', $before)->execute());
	}
}
