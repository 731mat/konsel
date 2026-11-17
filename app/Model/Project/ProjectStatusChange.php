<?php

declare(strict_types=1);

namespace App\Model\Project;

use App\Model\Shared\IntegerId;
use Doctrine\ORM\Mapping as ORM;

/**
 * Záznam o změně fáze plánu (součást časové osy).
 */
#[ORM\Entity]
#[ORM\Table(name: 'project_status_change')]
final class ProjectStatusChange
{
	use IntegerId;

	#[ORM\ManyToOne(targetEntity: Project::class, inversedBy: 'statusChanges')]
	#[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
	private Project $project;

	#[ORM\Column(length: 20, enumType: ProjectStatus::class)]
	private ProjectStatus $fromStatus;

	#[ORM\Column(length: 20, enumType: ProjectStatus::class)]
	private ProjectStatus $toStatus;

	#[ORM\Column]
	private \DateTimeImmutable $changedAt;

	#[ORM\Column(length: 200)]
	private string $changedByName;

	#[ORM\Column(type: 'text', nullable: true)]
	private ?string $note;

	/**
	 * @internal použijte Project::changeStatus()
	 */
	public function __construct(Project $project, ProjectStatus $from, ProjectStatus $to, \DateTimeImmutable $changedAt, string $changedByName, ?string $note)
	{
		$this->project = $project;
		$this->fromStatus = $from;
		$this->toStatus = $to;
		$this->changedAt = $changedAt;
		$this->changedByName = mb_substr($changedByName, 0, 200);
		$note = $note === null ? null : trim($note);
		$this->note = $note === '' ? null : $note;
	}

	public function getProject(): Project
	{
		return $this->project;
	}

	public function getFromStatus(): ProjectStatus
	{
		return $this->fromStatus;
	}

	public function getToStatus(): ProjectStatus
	{
		return $this->toStatus;
	}

	public function getChangedAt(): \DateTimeImmutable
	{
		return $this->changedAt;
	}

	public function getChangedByName(): string
	{
		return $this->changedByName;
	}

	public function getNote(): ?string
	{
		return $this->note;
	}
}
