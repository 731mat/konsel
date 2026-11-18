<?php

declare(strict_types=1);

namespace App\Model\Audit;

use App\Model\Municipality\Municipality;
use App\Model\Shared\IntegerId;
use App\Model\Shared\TenantOwned;
use App\Model\User\User;
use Doctrine\ORM\Mapping as ORM;

/**
 * Jedna změna v historii: kdo, kdy, co a z čeho na co. Zapisuje DoctrineAuditSubscriber, nemění se.
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'audit_entry')]
#[ORM\Index(name: 'audit_entry_municipality_idx', columns: ['municipality_id', 'created_at'])]
#[ORM\Index(name: 'audit_entry_meeting_idx', columns: ['meeting_id'])]
#[ORM\Index(name: 'audit_entry_entity_idx', columns: ['entity_type', 'entity_id'])]
#[ORM\Index(name: 'audit_entry_related_idx', columns: ['related_id'])]
final class AuditEntry implements TenantOwned
{
	use IntegerId;

	#[ORM\ManyToOne(targetEntity: Municipality::class)]
	#[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
	private Municipality $municipality;

	/**
	 * Zasedání, ke kterému změna patří (bez cizího klíče – historie přežije i smazané zasedání).
	 */
	#[ORM\Column(nullable: true)]
	private ?int $meetingId;

	#[ORM\Column(length: 40, enumType: AuditEntityType::class)]
	private AuditEntityType $entityType;

	#[ORM\Column]
	private int $entityId;

	/**
	 * Nadřazený záznam: u hlasu hlasování, u úkolu usnesení (historie usnesení je tak pohromadě).
	 */
	#[ORM\Column(nullable: true)]
	private ?int $relatedId = null;

	#[ORM\Column(length: 255)]
	private string $entityLabel;

	#[ORM\Column(length: 20, enumType: AuditAction::class)]
	private AuditAction $action;

	/**
	 * @var array<string, array{?string, ?string}>|null pole → [původní, nová hodnota] (čitelně)
	 */
	#[ORM\Column(type: 'json', nullable: true)]
	private ?array $changes;

	#[ORM\ManyToOne(targetEntity: User::class)]
	#[ORM\JoinColumn(onDelete: 'SET NULL')]
	private ?User $actorUser;

	#[ORM\Column(length: 150)]
	private string $actorName;

	#[ORM\Column(length: 45, nullable: true)]
	private ?string $ip;

	#[ORM\Column]
	private \DateTimeImmutable $createdAt;

	/**
	 * @param array<string, array{?string, ?string}>|null $changes
	 */
	public function __construct(
		Municipality $municipality,
		?int $meetingId,
		AuditEntityType $entityType,
		int $entityId,
		string $entityLabel,
		AuditAction $action,
		?array $changes,
		?User $actorUser,
		string $actorName,
		?string $ip,
		\DateTimeImmutable $createdAt,
	) {
		$this->municipality = $municipality;
		$this->meetingId = $meetingId;
		$this->entityType = $entityType;
		$this->entityId = $entityId;
		$this->entityLabel = $entityLabel;
		$this->action = $action;
		$this->changes = $changes;
		$this->actorUser = $actorUser;
		$this->actorName = $actorName;
		$this->ip = $ip;
		$this->createdAt = $createdAt;
	}

	public function getMunicipality(): Municipality
	{
		return $this->municipality;
	}

	public function getMeetingId(): ?int
	{
		return $this->meetingId;
	}

	public function getEntityType(): AuditEntityType
	{
		return $this->entityType;
	}

	public function getEntityId(): int
	{
		return $this->entityId;
	}

	public function getRelatedId(): ?int
	{
		return $this->relatedId;
	}

	public function getEntityLabel(): string
	{
		return $this->entityLabel;
	}

	public function getAction(): AuditAction
	{
		return $this->action;
	}

	/**
	 * @return array<string, array{?string, ?string}>
	 */
	public function getChanges(): array
	{
		return $this->changes ?? [];
	}

	public function getActorUser(): ?User
	{
		return $this->actorUser;
	}

	public function getActorName(): string
	{
		return $this->actorName;
	}

	public function getIp(): ?string
	{
		return $this->ip;
	}

	public function getCreatedAt(): \DateTimeImmutable
	{
		return $this->createdAt;
	}
}
