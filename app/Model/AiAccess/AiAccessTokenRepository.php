<?php

declare(strict_types=1);

namespace App\Model\AiAccess;

use App\Model\Municipality\Municipality;
use App\Model\Shared\Exception\NotFoundException;
use App\Model\User\User;

interface AiAccessTokenRepository
{
	/**
	 * @throws NotFoundException
	 */
	public function get(int $id): AiAccessToken;

	/**
	 * Napříč obcemi – obec se pozná až podle tokenu.
	 */
	public function findByTokenHash(string $hash): ?AiAccessToken;

	public function findByRefreshHash(string $hash): ?AiAccessToken;

	/**
	 * @return list<AiAccessToken>
	 */
	public function findByUser(User $user): array;

	/**
	 * @return list<AiAccessToken>
	 */
	public function findByMunicipality(Municipality $municipality): array;

	public function add(AiAccessToken $token): void;

	/**
	 * Smaže odvolaná a vypršelá připojení starší než $before.
	 */
	public function deleteStale(\DateTimeImmutable $before): int;
}
