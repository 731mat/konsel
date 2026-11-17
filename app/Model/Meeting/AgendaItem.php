<?php

declare(strict_types=1);

namespace App\Model\Meeting;

use App\Model\Municipality\Municipality;
use App\Model\People\Person;
use App\Model\Project\Project;
use App\Model\Shared\Exception\ValidationException;
use App\Model\Shared\IntegerId;
use App\Model\Shared\TenantOwned;
use App\Model\Shared\Timestampable;
use App\Model\Shared\TimestampableTrait;
use Doctrine\ORM\Mapping as ORM;

/**
 * Bod programu zasedání. Vzniká, mění se a přesouvá jen přes metody Meeting (agregát hlídá pořadí a stav).
 */
#[ORM\Entity]
#[ORM\Table(name: 'agenda_item')]
final class AgendaItem implements TenantOwned, Timestampable
{
	use IntegerId;
	use TimestampableTrait;

	#[ORM\ManyToOne(targetEntity: Municipality::class)]
	#[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
	private Municipality $municipality;

	#[ORM\ManyToOne(targetEntity: Meeting::class, inversedBy: 'agendaItems')]
	#[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
	private Meeting $meeting;

	/**
	 * Pořadí v celém programu (podbody hned za svým bodem). Číslo pro zobrazení dává getNumber().
	 */
	#[ORM\Column]
	private int $position;

	/**
	 * Nadřazený bod – podbod se hlasuje samostatně (např. 26.1 schválení projektu, 26.2 dodavatele).
	 * Jen jedna úroveň.
	 */
	#[ORM\ManyToOne(targetEntity: self::class)]
	#[ORM\JoinColumn(onDelete: 'CASCADE')]
	private ?self $parent = null;

	/**
	 * Optimistický zámek proti přepsání souběžné úpravy (ADR A14).
	 */
	#[ORM\Version]
	#[ORM\Column(type: 'integer')]
	private int $version = 1;

	#[ORM\Column(length: 300)]
	private string $title;

	#[ORM\Column(type: 'text', nullable: true)]
	private ?string $description = null;

	#[ORM\Column(type: 'text', nullable: true)]
	private ?string $proposedResolution = null;

	/**
	 * Předkladatel bodu.
	 */
	#[ORM\ManyToOne(targetEntity: Person::class)]
	private ?Person $rapporteur = null;

	/**
	 * Shrnutí diskuse k bodu do zápisu (prostý text s odstavci).
	 */
	#[ORM\Column(type: 'text', nullable: true)]
	private ?string $discussion = null;

	/**
	 * Bod doplněný do programu až na zasedání (o doplnění se hlasuje při schválení programu).
	 */
	#[ORM\Column]
	private bool $addedDuringMeeting = false;

	/**
	 * Plán, ke kterému bod patří (stejné téma napříč zasedáními).
	 */
	#[ORM\ManyToOne(targetEntity: Project::class)]
	#[ORM\JoinColumn(onDelete: 'SET NULL')]
	private ?Project $project = null;

	/**
	 * @internal použijte Meeting::addAgendaItem()
	 */
	public function __construct(Meeting $meeting, int $position, AgendaItemContent $content, ?self $parent = null)
	{
		$this->municipality = $meeting->getMunicipality();
		$this->meeting = $meeting;
		$this->position = $position;
		$this->parent = $parent;
		$this->changeContent($content);
	}

	/**
	 * @internal použijte Meeting::changeAgendaItemParent()
	 */
	public function changeParent(?self $parent): void
	{
		$this->parent = $parent;
	}

	public function getParent(): ?self
	{
		return $this->parent;
	}

	public function isSubItem(): bool
	{
		return $this->parent !== null;
	}

	/**
	 * Bod má podbody – hlasuje se o nich, ne o bodu samotném.
	 */
	public function hasSubItems(): bool
	{
		return $this->meeting->getSubItems($this) !== [];
	}

	/**
	 * Číslo bodu v programu: „5“, podbod „5.2“.
	 */
	public function getNumber(): string
	{
		return $this->parent === null ? (string) $this->getTopNumber() : "{$this->getTopNumber()}.{$this->getSubNumber()}";
	}

	/**
	 * Pořadí hlavního bodu (u podbodu pořadí jeho bodu).
	 */
	public function getTopNumber(): int
	{
		$top = 0;
		foreach ($this->meeting->getAgendaItems() as $item) {
			if ($item->parent === null) {
				$top++;
			}

			if ($item === $this) {
				return $top;
			}
		}

		return $top;
	}

	/**
	 * Pořadí podbodu v rámci bodu (null u hlavního bodu).
	 */
	public function getSubNumber(): ?int
	{
		if ($this->parent === null) {
			return null;
		}

		$index = array_search($this, $this->meeting->getSubItems($this->parent), true);

		return is_int($index) ? $index + 1 : null;
	}

	/**
	 * @internal použijte Meeting::updateAgendaItem()
	 */
	public function changeContent(AgendaItemContent $content): void
	{
		$this->title = $content->title;
		$this->description = $content->description;
		$this->proposedResolution = $content->proposedResolution;
		$this->rapporteur = $content->rapporteur;
	}

	/**
	 * @internal použijte Meeting::recordDiscussion()
	 */
	public function changeDiscussion(?string $discussion): void
	{
		$this->discussion = $discussion;
	}

	/**
	 * @internal použijte Meeting::addAgendaItemDuringMeeting()
	 */
	public function markAddedDuringMeeting(): void
	{
		$this->addedDuringMeeting = true;
	}

	public function getDiscussion(): ?string
	{
		return $this->discussion;
	}

	public function isAddedDuringMeeting(): bool
	{
		return $this->addedDuringMeeting;
	}

	/**
	 * @internal pořadí přepočítává Meeting
	 */
	public function moveTo(int $position): void
	{
		$this->position = $position;
	}

	/**
	 * Propojení s plánem jde měnit kdykoli (i u proběhlého zasedání) – nejde o obsah programu.
	 *
	 * @throws ValidationException
	 */
	public function linkProject(?Project $project): void
	{
		if ($project !== null && $project->getMunicipality() !== $this->municipality) {
			throw new ValidationException('Plán patří k jiné obci.', 'project');
		}

		$this->project = $project;
	}

	public function getProject(): ?Project
	{
		return $this->project;
	}

	public function getMunicipality(): Municipality
	{
		return $this->municipality;
	}

	public function getMeeting(): Meeting
	{
		return $this->meeting;
	}

	/**
	 * Číslo bodu v programu (1, 2, …).
	 */
	public function getPosition(): int
	{
		return $this->position;
	}

	public function getTitle(): string
	{
		return $this->title;
	}

	public function getDescription(): ?string
	{
		return $this->description;
	}

	public function getProposedResolution(): ?string
	{
		return $this->proposedResolution;
	}

	public function getRapporteur(): ?Person
	{
		return $this->rapporteur;
	}

	public function getContent(): AgendaItemContent
	{
		return new AgendaItemContent($this->title, $this->description, $this->proposedResolution, $this->rapporteur);
	}
}
