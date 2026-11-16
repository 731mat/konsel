<?php

declare(strict_types=1);

namespace App\Model\Access;

use App\Model\Meeting\Meeting;
use App\Model\Municipality\Municipality;
use App\Model\People\Person;
use App\Model\Shared\IntegerId;
use App\Model\Shared\TenantOwned;
use Doctrine\ORM\Mapping as ORM;

/**
 * Osobní odkaz zastupitele k zasedání (ADR A13). V DB je jen SHA-256 hash tokenu – samotný odkaz
 * se ukáže jen při vytvoření (a pošle e-mailem v T20). Platnost končí expirací nebo odvoláním.
 */
#[ORM\Entity]
#[ORM\Table(name: 'meeting_access_token')]
final class MeetingAccessToken implements TenantOwned
{
	use IntegerId;

	#[ORM\ManyToOne(targetEntity: Municipality::class)]
	#[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
	private Municipality $municipality;

	#[ORM\ManyToOne(targetEntity: Meeting::class)]
	#[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
	private Meeting $meeting;

	#[ORM\ManyToOne(targetEntity: Person::class)]
	#[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
	private Person $person;

	#[ORM\Column(length: 64, unique: true)]
	private string $tokenHash;

	#[ORM\Column]
	private \DateTimeImmutable $createdAt;

	#[ORM\Column]
	private \DateTimeImmutable $expiresAt;

	#[ORM\Column(nullable: true)]
	private ?\DateTimeImmutable $revokedAt = null;

	#[ORM\Column(nullable: true)]
	private ?\DateTimeImmutable $lastUsedAt = null;

	#[ORM\Column]
	private int $useCount = 0;

	public function __construct(Meeting $meeting, Person $person, string $tokenHash, \DateTimeImmutable $createdAt, \DateTimeImmutable $expiresAt)
	{
		$this->municipality = $meeting->getMunicipality();
		$this->meeting = $meeting;
		$this->person = $person;
		$this->tokenHash = $tokenHash;
		$this->createdAt = $createdAt;
		$this->expiresAt = $expiresAt;
	}

	public static function hash(string $token): string
	{
		return hash('sha256', $token);
	}

	public function isValidAt(\DateTimeImmutable $now): bool
	{
		return $this->revokedAt === null && $now < $this->expiresAt;
	}

	public function revoke(\DateTimeImmutable $at): void
	{
		$this->revokedAt ??= $at;
	}

	public function recordUse(\DateTimeImmutable $at): void
	{
		$this->lastUsedAt = $at;
		$this->useCount++;
	}

	public function getMunicipality(): Municipality
	{
		return $this->municipality;
	}

	public function getMeeting(): Meeting
	{
		return $this->meeting;
	}

	public function getPerson(): Person
	{
		return $this->person;
	}

	public function getCreatedAt(): \DateTimeImmutable
	{
		return $this->createdAt;
	}

	public function getExpiresAt(): \DateTimeImmutable
	{
		return $this->expiresAt;
	}

	public function getRevokedAt(): ?\DateTimeImmutable
	{
		return $this->revokedAt;
	}

	public function getLastUsedAt(): ?\DateTimeImmutable
	{
		return $this->lastUsedAt;
	}

	public function getUseCount(): int
	{
		return $this->useCount;
	}
}
