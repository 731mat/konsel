<?php

declare(strict_types=1);

namespace App\Model\Access;

use App\Core\Tenancy\TenantFilter;
use App\Model\Meeting\Meeting;
use App\Model\People\Person;
use App\Model\Shared\Exception\NotFoundException;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineMeetingAccessTokenRepository implements MeetingAccessTokenRepository
{
	public function __construct(
		private EntityManagerInterface $entityManager,
	) {}

	public function get(int $id): MeetingAccessToken
	{
		return $this->entityManager->find(MeetingAccessToken::class, $id) ?? throw new NotFoundException('Odkaz neexistuje.');
	}

	public function findById(int $id): ?MeetingAccessToken
	{
		return $this->withoutTenantFilter(fn(): ?MeetingAccessToken => $this->entityManager->find(MeetingAccessToken::class, $id));
	}

	public function findByHash(string $tokenHash): ?MeetingAccessToken
	{
		return $this->withoutTenantFilter(fn(): ?MeetingAccessToken => $this->entityManager->getRepository(MeetingAccessToken::class)->findOneBy([
			'tokenHash' => $tokenHash,
		]));
	}

	public function findByMeeting(Meeting $meeting): array
	{
		return $this->entityManager->getRepository(MeetingAccessToken::class)->findBy([
			'meeting' => $meeting,
		], [
			'createdAt' => 'DESC',
			'id' => 'DESC',
		]);
	}

	public function findActive(Meeting $meeting, Person $person): array
	{
		return $this->entityManager->getRepository(MeetingAccessToken::class)->findBy([
			'meeting' => $meeting,
			'person' => $person,
			'revokedAt' => null,
		]);
	}

	public function add(MeetingAccessToken $token): void
	{
		$this->entityManager->persist($token);
	}

	/**
	 * @template T
	 * @param callable(): T $query
	 * @return T
	 */
	private function withoutTenantFilter(callable $query): mixed
	{
		$filters = $this->entityManager->getFilters();
		$enabled = $filters->isEnabled(TenantFilter::Name);
		if ($enabled) {
			$filters->disable(TenantFilter::Name);
		}

		try {
			return $query();
		} finally {
			if ($enabled) {
				$filters->enable(TenantFilter::Name);
			}
		}
	}
}
