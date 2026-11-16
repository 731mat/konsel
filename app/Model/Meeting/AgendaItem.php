<?php

declare(strict_types=1);

namespace App\Model\Meeting;

use App\Model\Municipality\Municipality;
use App\Model\People\Person;
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

	#[ORM\Column]
	private int $position;

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
	 * @internal použijte Meeting::addAgendaItem()
	 */
	public function __construct(Meeting $meeting, int $position, AgendaItemContent $content)
	{
		$this->municipality = $meeting->getMunicipality();
		$this->meeting = $meeting;
		$this->position = $position;
		$this->changeContent($content);
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
	 * @internal pořadí přepočítává Meeting
	 */
	public function moveTo(int $position): void
	{
		$this->position = $position;
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
