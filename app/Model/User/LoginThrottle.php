<?php

declare(strict_types=1);

namespace App\Model\User;

use Psr\Clock\ClockInterface;

/**
 * Omezení počtu neúspěšných přihlášení: na e-mail i na IP adresu v klouzavém okně.
 */
final readonly class LoginThrottle
{
	public const int MaxFailuresPerEmail = 5;

	public const int MaxFailuresPerIp = 20;

	public const string Window = '15 minutes';

	public function __construct(
		private LoginAttemptRepository $attempts,
		private ClockInterface $clock,
	) {}

	public function isBlocked(string $email, string $ip): bool
	{
		$since = $this->clock->now()->modify('-' . self::Window);

		return $this->attempts->countRecentFailuresForEmail($email, $since) >= self::MaxFailuresPerEmail
			|| $this->attempts->countRecentFailuresForIp($ip, $since) >= self::MaxFailuresPerIp;
	}

	public function record(string $email, string $ip, bool $successful): void
	{
		$this->attempts->add(new LoginAttempt($email, $ip, $successful, $this->clock->now()));
	}
}
