<?php

declare(strict_types=1);

namespace App\Model\AiAccess;

interface OAuthClientRepository
{
	public function findByClientId(string $clientId): ?OAuthClient;

	public function add(OAuthClient $client): void;

	/**
	 * Smaže registrace starší než $before, ke kterým nikdo nepřipojil účet (registrace je veřejná).
	 */
	public function deleteUnused(\DateTimeImmutable $before): int;
}
