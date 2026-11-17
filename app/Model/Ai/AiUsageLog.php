<?php

declare(strict_types=1);

namespace App\Model\Ai;

use App\Model\Municipality\Municipality;
use App\Model\Shared\IntegerId;
use App\Model\Shared\TenantOwned;
use App\Model\User\User;
use Doctrine\ORM\Mapping as ORM;

/**
 * Jedno použití AI: kdo, kdy, k čemu a kolik tokenů (spotřebu platí obec na svém účtu OpenAI).
 */
#[ORM\Entity]
#[ORM\Table(name: 'ai_usage_log')]
final class AiUsageLog implements TenantOwned
{
	use IntegerId;

	#[ORM\ManyToOne(targetEntity: Municipality::class)]
	#[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
	private Municipality $municipality;

	#[ORM\ManyToOne(targetEntity: User::class)]
	#[ORM\JoinColumn(onDelete: 'SET NULL')]
	private ?User $user;

	#[ORM\Column(length: 50, enumType: AiPurpose::class)]
	private AiPurpose $purpose;

	#[ORM\Column(length: 100)]
	private string $model;

	#[ORM\Column]
	private int $promptTokens;

	#[ORM\Column]
	private int $completionTokens;

	#[ORM\Column]
	private \DateTimeImmutable $createdAt;

	public function __construct(Municipality $municipality, ?User $user, AiPurpose $purpose, AiCompletion $completion, \DateTimeImmutable $createdAt)
	{
		$this->municipality = $municipality;
		$this->user = $user;
		$this->purpose = $purpose;
		$this->model = $completion->model;
		$this->promptTokens = $completion->inputTokens;
		$this->completionTokens = $completion->outputTokens;
		$this->createdAt = $createdAt;
	}

	public function getMunicipality(): Municipality
	{
		return $this->municipality;
	}

	public function getUser(): ?User
	{
		return $this->user;
	}

	public function getPurpose(): AiPurpose
	{
		return $this->purpose;
	}

	public function getModel(): string
	{
		return $this->model;
	}

	public function getPromptTokens(): int
	{
		return $this->promptTokens;
	}

	public function getCompletionTokens(): int
	{
		return $this->completionTokens;
	}

	public function getCreatedAt(): \DateTimeImmutable
	{
		return $this->createdAt;
	}
}
