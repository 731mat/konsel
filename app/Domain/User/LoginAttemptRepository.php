<?php

declare(strict_types=1);

namespace App\Domain\User;

interface LoginAttemptRepository
{
	public function add(LoginAttempt $attempt): void;

	/**
	 * Počet neúspěšných pokusů pro e-mail od $since, nepočítaje pokusy před posledním úspěšným přihlášením.
	 */
	public function countRecentFailuresForEmail(string $email, \DateTimeImmutable $since): int;

	public function countRecentFailuresForIp(string $ip, \DateTimeImmutable $since): int;
}
