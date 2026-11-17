<?php

declare(strict_types=1);

namespace App\Model\Meeting;

use App\Model\Municipality\Municipality;
use App\Model\Shared\IntegerId;
use App\Model\Shared\TenantOwned;
use App\Model\User\User;
use Doctrine\ORM\Mapping as ORM;

/**
 * Kdo právě upravuje bod (diskusi) na obrazovce průběhu. Platí TTL sekund, prohlížeč ho průběžně obnovuje (ADR A14).
 */
#[ORM\Entity]
#[ORM\Table(name: 'agenda_item_lock')]
#[ORM\UniqueConstraint(columns: ['agenda_item_id'])]
final class AgendaItemLock implements TenantOwned
{
	use IntegerId;

	public const int TtlSeconds = 60;

	#[ORM\ManyToOne(targetEntity: Municipality::class)]
	#[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
	private Municipality $municipality;

	#[ORM\ManyToOne(targetEntity: AgendaItem::class)]
	#[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
	private AgendaItem $agendaItem;

	#[ORM\ManyToOne(targetEntity: User::class)]
	#[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
	private User $user;

	#[ORM\Column]
	private \DateTimeImmutable $acquiredAt;

	#[ORM\Column]
	private \DateTimeImmutable $expiresAt;

	public function __construct(AgendaItem $item, User $user, \DateTimeImmutable $now)
	{
		$this->municipality = $item->getMunicipality();
		$this->agendaItem = $item;
		$this->user = $user;
		$this->acquiredAt = $now;
		$this->expiresAt = $now->modify('+' . self::TtlSeconds . ' seconds');
	}

	/**
	 * Obnovení (heartbeat) nebo převzetí vypršelého zámku.
	 */
	public function renew(User $user, \DateTimeImmutable $now): void
	{
		if ($this->user !== $user) {
			$this->user = $user;
			$this->acquiredAt = $now;
		}

		$this->expiresAt = $now->modify('+' . self::TtlSeconds . ' seconds');
	}

	public function isExpired(\DateTimeImmutable $now): bool
	{
		return $this->expiresAt <= $now;
	}

	public function isHeldBy(User $user, \DateTimeImmutable $now): bool
	{
		return $this->user === $user && !$this->isExpired($now);
	}

	public function getMunicipality(): Municipality
	{
		return $this->municipality;
	}

	public function getAgendaItem(): AgendaItem
	{
		return $this->agendaItem;
	}

	public function getUser(): User
	{
		return $this->user;
	}

	public function getExpiresAt(): \DateTimeImmutable
	{
		return $this->expiresAt;
	}
}
