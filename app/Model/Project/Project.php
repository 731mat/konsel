<?php

declare(strict_types=1);

namespace App\Model\Project;

use App\Model\Municipality\Municipality;
use App\Model\People\Person;
use App\Model\Shared\Exception\InvalidStateException;
use App\Model\Shared\Exception\ValidationException;
use App\Model\Shared\IntegerId;
use App\Model\Shared\TenantOwned;
use App\Model\Shared\Timestampable;
use App\Model\Shared\TimestampableTrait;
use App\Model\Shared\ValueObject\Email;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Plán obce – dlouhodobé téma („Postavit školu“), které se táhne přes více zasedání.
 * Diskuse a soubory jsou Comment/Attachment s vazbou na plán, body programu se k plánu propojují.
 */
#[ORM\Entity]
#[ORM\Table(name: 'project')]
final class Project implements TenantOwned, Timestampable
{
	use IntegerId;
	use TimestampableTrait;

	#[ORM\ManyToOne(targetEntity: Municipality::class)]
	#[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
	private Municipality $municipality;

	#[ORM\Column(length: 200)]
	private string $title;

	#[ORM\Column(type: 'text', nullable: true)]
	private ?string $description = null;

	#[ORM\Column(length: 20, enumType: ProjectStatus::class)]
	private ProjectStatus $status;

	#[ORM\Column(length: 20, enumType: ProjectPriority::class)]
	private ProjectPriority $priority = ProjectPriority::Normal;

	#[ORM\ManyToOne(targetEntity: Person::class)]
	#[ORM\JoinColumn(onDelete: 'SET NULL')]
	private ?Person $responsible = null;

	/**
	 * Odhad nákladů v Kč.
	 */
	#[ORM\Column(nullable: true)]
	private ?int $estimatedCost = null;

	/**
	 * Plán je vidět na veřejné stránce obce (fáze, priorita, veřejné komentáře).
	 */
	#[ORM\Column]
	private bool $public = false;

	/**
	 * Kdo plán založil (jméno uživatele úřadu nebo občana u podnětu).
	 */
	#[ORM\Column(length: 200)]
	private string $createdByName;

	/**
	 * Kontakt na občana u podnětu (jen pro úřad, veřejně se nezobrazuje).
	 */
	#[ORM\Column(length: 180, nullable: true)]
	private ?string $submitterEmail = null;

	/**
	 * IP adresa odesílatele podnětu – jen kvůli omezení zneužití.
	 */
	#[ORM\Column(length: 45, nullable: true)]
	private ?string $submitterIp = null;

	/**
	 * @var Collection<int, ProjectStatusChange>
	 */
	#[ORM\OneToMany(targetEntity: ProjectStatusChange::class, mappedBy: 'project', cascade: ['persist', 'remove'], orphanRemoval: true)]
	#[ORM\OrderBy([
		'changedAt' => 'ASC',
		'id' => 'ASC',
	])]
	private Collection $statusChanges;

	public function __construct(Municipality $municipality, ProjectDetails $details, string $createdByName, ProjectStatus $status = ProjectStatus::Idea)
	{
		$this->municipality = $municipality;
		$this->status = $status;
		$this->createdByName = mb_substr(trim($createdByName), 0, 200);
		$this->statusChanges = new ArrayCollection();
		$this->update($details);
	}

	/**
	 * Podnět občana z veřejné stránky – čeká na posouzení úřadem, do té doby není veřejný.
	 */
	public static function citizenProposal(Municipality $municipality, ProjectDetails $details, string $name, Email $email, ?string $ip): self
	{
		$name = trim($name);
		if ($name === '' || mb_strlen($name) > 100) {
			throw new ValidationException('Zadejte své jméno (nejvýše 100 znaků).', 'name');
		}

		$project = new self($municipality, $details, $name . ' (podnět občana)', ProjectStatus::Submitted);
		$project->submitterEmail = $email->value;
		$project->submitterIp = $ip === null ? null : mb_substr($ip, 0, 45);

		return $project;
	}

	/**
	 * @throws ValidationException
	 */
	public function update(ProjectDetails $details): void
	{
		if ($details->responsible !== null && $details->responsible->getMunicipality() !== $this->municipality) {
			throw new ValidationException('Odpovědná osoba patří k jiné obci.', 'responsible');
		}

		$this->title = $details->title;
		$this->description = $details->description;
		$this->priority = $details->priority;
		$this->responsible = $details->responsible;
		$this->estimatedCost = $details->estimatedCost;
	}

	/**
	 * @throws InvalidStateException stejná fáze
	 */
	public function changeStatus(ProjectStatus $status, \DateTimeImmutable $at, string $changedByName, ?string $note = null): ProjectStatusChange
	{
		if ($status === $this->status) {
			throw new InvalidStateException("Plán už je ve fázi „{$status->label()}“.");
		}

		$change = new ProjectStatusChange($this, $this->status, $status, $at, $changedByName, $note);
		$this->statusChanges->add($change);
		$this->status = $status;

		return $change;
	}

	public function publish(): void
	{
		if ($this->status === ProjectStatus::Submitted) {
			throw new InvalidStateException('Nový podnět nejdřív posuďte (převeďte do fáze Námět nebo dál), pak ho lze zveřejnit.');
		}

		$this->public = true;
	}

	public function unpublish(): void
	{
		$this->public = false;
	}

	public function getMunicipality(): Municipality
	{
		return $this->municipality;
	}

	public function getTitle(): string
	{
		return $this->title;
	}

	public function getDescription(): ?string
	{
		return $this->description;
	}

	public function getStatus(): ProjectStatus
	{
		return $this->status;
	}

	public function getPriority(): ProjectPriority
	{
		return $this->priority;
	}

	public function getResponsible(): ?Person
	{
		return $this->responsible;
	}

	public function getEstimatedCost(): ?int
	{
		return $this->estimatedCost;
	}

	public function isPublic(): bool
	{
		return $this->public;
	}

	public function getCreatedByName(): string
	{
		return $this->createdByName;
	}

	public function isCitizenProposal(): bool
	{
		return $this->submitterEmail !== null;
	}

	public function getSubmitterEmail(): ?Email
	{
		return $this->submitterEmail === null ? null : new Email($this->submitterEmail);
	}

	/**
	 * @return list<ProjectStatusChange>
	 */
	public function getStatusChanges(): array
	{
		return array_values($this->statusChanges->toArray());
	}
}
