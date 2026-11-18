<?php

declare(strict_types=1);

namespace App\Model\AiAccess;

use App\Core\Tenancy\TenantFilterSuspender;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineOAuthClientRepository implements OAuthClientRepository
{
	public function __construct(
		private EntityManagerInterface $entityManager,
	) {}

	public function findByClientId(string $clientId): ?OAuthClient
	{
		return $this->entityManager->getRepository(OAuthClient::class)->findOneBy([
			'clientId' => $clientId,
		]);
	}

	public function add(OAuthClient $client): void
	{
		$this->entityManager->persist($client);
	}

	public function deleteUnused(\DateTimeImmutable $before): int
	{
		return TenantFilterSuspender::run($this->entityManager, fn(): int => $this->entityManager->createQuery(
			'DELETE FROM ' . OAuthClient::class . ' c
			WHERE c.createdAt < :before
				AND NOT EXISTS (SELECT t.id FROM ' . AiAccessToken::class . ' t WHERE t.client = c)
				AND NOT EXISTS (SELECT a.id FROM ' . AuthorizationCode::class . ' a WHERE a.client = c)',
		)->setParameter('before', $before)->execute());
	}
}
