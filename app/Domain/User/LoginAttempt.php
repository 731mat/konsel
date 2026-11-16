<?php

declare(strict_types=1);

namespace App\Domain\User;

use App\Domain\Shared\IntegerId;
use Doctrine\ORM\Mapping as ORM;

/**
 * Záznam o pokusu o přihlášení – podklad pro omezení počtu pokusů a pro audit.
 */
#[ORM\Entity]
#[ORM\Table(name: 'login_attempt')]
#[ORM\Index(name: 'IDX_LOGIN_ATTEMPT_EMAIL', columns: ['email', 'attempted_at'])]
#[ORM\Index(name: 'IDX_LOGIN_ATTEMPT_IP', columns: ['ip', 'attempted_at'])]
final class LoginAttempt
{
	use IntegerId;

	#[ORM\Column(length: 180)]
	private string $email;

	#[ORM\Column(length: 45)]
	private string $ip;

	#[ORM\Column]
	private bool $successful;

	#[ORM\Column]
	private \DateTimeImmutable $attemptedAt;

	public function __construct(string $email, string $ip, bool $successful, \DateTimeImmutable $attemptedAt)
	{
		$this->email = mb_substr(mb_strtolower(trim($email)), 0, 180);
		$this->ip = mb_substr($ip, 0, 45);
		$this->successful = $successful;
		$this->attemptedAt = $attemptedAt;
	}

	public function getEmail(): string
	{
		return $this->email;
	}

	public function getIp(): string
	{
		return $this->ip;
	}

	public function isSuccessful(): bool
	{
		return $this->successful;
	}

	public function getAttemptedAt(): \DateTimeImmutable
	{
		return $this->attemptedAt;
	}
}
