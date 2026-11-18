<?php

declare(strict_types=1);

namespace App\Model\AiAccess;

use App\Core\Tenancy\TenantFilterSuspender;
use App\Model\Municipality\Municipality;
use App\Model\Shared\Exception\NotFoundException;
use App\Model\User\User;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineAiAccessTokenRepository implements AiAccessTokenRepository
{
	public function __construct(
		private EntityManagerInterface $entityManager,
	) {}

	public function get(int $id): AiAccessToken
	{
		return $this->entityManager->find(AiAccessToken::class, $id) ?? throw new NotFoundException('Připojení neexistuje.');
	}

	public function findByTokenHash(string $hash): ?AiAccessToken
	{
		return TenantFilterSuspender::run($this->entityManager, fn(): ?AiAccessToken => $this->entityManager->getRepository(AiAccessToken::class)->findOneBy([
			'tokenHash' => $hash,
		]));
	}

	public function findByRefreshHash(string $hash): ?AiAccessToken
	{
		return TenantFilterSuspender::run($this->entityManager, fn(): ?AiAccessToken => $this->entityManager->getRepository(AiAccessToken::class)->findOneBy([
			'refreshTokenHash' => $hash,
		]));
	}

	public function findByUser(User $user): array
	{
		return $this->entityManager->getRepository(AiAccessToken::class)->findBy([
			'user' => $user,
		], [
			'createdAt' => 'DESC',
			'id' => 'DESC',
		]);
	}

	public function findByMunicipality(Municipality $municipality): array
	{
		return $this->entityManager->getRepository(AiAccessToken::class)->findBy([
			'municipality' => $municipality,
		], [
			'createdAt' => 'DESC',
			'id' => 'DESC',
		]);
	}

	public function add(AiAccessToken $token): void
	{
		$this->entityManager->persist($token);
	}

	public function deleteStale(\DateTimeImmutable $before): int
	{
		return TenantFilterSuspender::run($this->entityManager, fn(): int => $this->entityManager->createQuery(
			'DELETE FROM ' . AiAccessToken::class . ' t
			WHERE t.revokedAt < :before
				OR (t.expiresAt < :before AND (t.refreshExpiresAt IS NULL OR t.refreshExpiresAt < :before))',
		)->setParameter('before', $before)->execute());
	}
}
