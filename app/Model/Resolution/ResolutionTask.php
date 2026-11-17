<?php

declare(strict_types=1);

namespace App\Model\Resolution;

use App\Model\Municipality\Municipality;
use App\Model\People\Person;
use App\Model\Shared\Exception\InvalidStateException;
use App\Model\Shared\Exception\ValidationException;
use App\Model\Shared\IntegerId;
use App\Model\Shared\TenantOwned;
use Doctrine\ORM\Mapping as ORM;

/**
 * Úkol vyplývající z usnesení („ukládá starostovi do 31. 3. podepsat smlouvu“) – hlídá se termín a splnění.
 */
#[ORM\Entity]
#[ORM\Table(name: 'resolution_task')]
#[ORM\Index(columns: ['status', 'due_date'])]
final class ResolutionTask implements TenantOwned
{
	use IntegerId;

	public const int MaxDescriptionLength = 500;

	#[ORM\ManyToOne(targetEntity: Municipality::class)]
	#[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
	private Municipality $municipality;

	#[ORM\ManyToOne(targetEntity: Resolution::class)]
	#[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
	private Resolution $resolution;

	#[ORM\Column(length: 500)]
	private string $description;

	/**
	 * Komu je úkol uložen (null = obecní úřad).
	 */
	#[ORM\ManyToOne(targetEntity: Person::class)]
	#[ORM\JoinColumn(onDelete: 'SET NULL')]
	private ?Person $assignee;

	#[ORM\Column(type: 'date_immutable', nullable: true)]
	private ?\DateTimeImmutable $dueDate;

	#[ORM\Column(length: 20, enumType: TaskStatus::class)]
	private TaskStatus $status = TaskStatus::Open;

	#[ORM\Column(type: 'text', nullable: true)]
	private ?string $note = null;

	#[ORM\Column(nullable: true)]
	private ?\DateTimeImmutable $completedAt = null;

	#[ORM\Column]
	private \DateTimeImmutable $createdAt;

	/**
	 * @throws ValidationException
	 */
	public function __construct(Resolution $resolution, string $description, ?Person $assignee, ?\DateTimeImmutable $dueDate, \DateTimeImmutable $now)
	{
		$this->municipality = $resolution->getMunicipality();
		$this->resolution = $resolution;
		$this->createdAt = $now;
		$this->change($description, $assignee, $dueDate);
	}

	/**
	 * @throws ValidationException
	 */
	public function change(string $description, ?Person $assignee, ?\DateTimeImmutable $dueDate): void
	{
		$description = trim((string) preg_replace('~\s+~u', ' ', $description));
		if ($description === '' || mb_strlen($description) > self::MaxDescriptionLength) {
			throw new ValidationException('Popis úkolu musí mít 1 až ' . self::MaxDescriptionLength . ' znaků.', 'description');
		}

		if ($assignee !== null && $assignee->getMunicipality() !== $this->municipality) {
			throw new ValidationException('Osoba patří k jiné obci.', 'assigneeId');
		}

		$this->description = $description;
		$this->assignee = $assignee;
		$this->dueDate = $dueDate?->setTime(0, 0);
	}

	/**
	 * @throws InvalidStateException
	 */
	public function complete(\DateTimeImmutable $now, ?string $note = null): void
	{
		$this->assertOpen();
		$this->status = TaskStatus::Done;
		$this->completedAt = $now;
		$this->note = self::normalizeNote($note) ?? $this->note;
	}

	/**
	 * @throws InvalidStateException
	 */
	public function cancel(?string $note = null): void
	{
		$this->assertOpen();
		$this->status = TaskStatus::Cancelled;
		$this->note = self::normalizeNote($note) ?? $this->note;
	}

	public function reopen(): void
	{
		$this->status = TaskStatus::Open;
		$this->completedAt = null;
	}

	/**
	 * Otevřený úkol po termínu (termín je poslední den, po jeho uplynutí je úkol po termínu).
	 */
	public function isOverdue(\DateTimeImmutable $now): bool
	{
		return $this->status === TaskStatus::Open && $this->dueDate !== null && $this->dueDate < $now->setTime(0, 0);
	}

	public function getMunicipality(): Municipality
	{
		return $this->municipality;
	}

	public function getResolution(): Resolution
	{
		return $this->resolution;
	}

	public function getDescription(): string
	{
		return $this->description;
	}

	public function getAssignee(): ?Person
	{
		return $this->assignee;
	}

	public function getDueDate(): ?\DateTimeImmutable
	{
		return $this->dueDate;
	}

	public function getStatus(): TaskStatus
	{
		return $this->status;
	}

	public function getNote(): ?string
	{
		return $this->note;
	}

	public function getCompletedAt(): ?\DateTimeImmutable
	{
		return $this->completedAt;
	}

	public function getCreatedAt(): \DateTimeImmutable
	{
		return $this->createdAt;
	}

	private function assertOpen(): void
	{
		if ($this->status !== TaskStatus::Open) {
			throw new InvalidStateException("Úkol je {$this->status->label()}.");
		}
	}

	private static function normalizeNote(?string $note): ?string
	{
		$note = $note === null ? null : trim($note);

		return $note === '' ? null : $note;
	}
}
