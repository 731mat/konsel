<?php

declare(strict_types=1);

namespace App\Model\AiAccess;

interface AuthorizationCodeRepository
{
	/**
	 * Napříč obcemi – token endpoint obec nezná.
	 */
	public function findByHash(string $hash): ?AuthorizationCode;

	public function add(AuthorizationCode $code): void;

	public function deleteExpired(\DateTimeImmutable $before): int;
}
