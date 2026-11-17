<?php

declare(strict_types=1);

namespace App\Model\Resolution;

use App\Model\Meeting\AgendaItem;
use App\Model\Meeting\Meeting;
use App\Model\Meeting\VotingRound;
use App\Model\Municipality\Municipality;
use App\Model\People\Body;
use App\Model\Shared\Exception\InvalidStateException;
use App\Model\Shared\Exception\ValidationException;
use App\Model\Shared\IntegerId;
use App\Model\Shared\TenantOwned;
use Doctrine\ORM\Mapping as ORM;

/**
 * Usnesení orgánu s číslem podle masky. Vzniká přijetím hlasování o návrhu, protinávrhu nebo volbě.
 */
#[ORM\Entity]
#[ORM\Table(name: 'resolution')]
#[ORM\UniqueConstraint(columns: ['body_id', 'scope_key', 'sequence'])]
#[ORM\UniqueConstraint(columns: ['voting_round_id'])]
final class Resolution implements TenantOwned
{
	use IntegerId;

	#[ORM\ManyToOne(targetEntity: Municipality::class)]
	#[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
	private Municipality $municipality;

	#[ORM\ManyToOne(targetEntity: Body::class)]
	#[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
	private Body $body;

	#[ORM\ManyToOne(targetEntity: Meeting::class)]
	#[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
	private Meeting $meeting;

	#[ORM\ManyToOne(targetEntity: AgendaItem::class)]
	#[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
	private AgendaItem $agendaItem;

	#[ORM\ManyToOne(targetEntity: VotingRound::class)]
	#[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
	private ?VotingRound $votingRound;

	/**
	 * Číslo podle masky orgánu, např. „12/2026“.
	 */
	#[ORM\Column(length: 100)]
	private string $number;

	/**
	 * Pořadí v řadě (rok / období / zasedání).
	 */
	#[ORM\Column]
	private int $sequence;

	#[ORM\Column(length: 50)]
	private string $scopeKey;

	#[ORM\Column(type: 'text')]
	private string $text;

	#[ORM\Column(length: 20, enumType: ResolutionStatus::class)]
	private ResolutionStatus $status = ResolutionStatus::Adopted;

	#[ORM\Column]
	private \DateTimeImmutable $adoptedAt;

	/**
	 * Poznámka ke stavu, např. „revokováno usnesením č. 12/2027“.
	 */
	#[ORM\Column(type: 'text', nullable: true)]
	private ?string $statusNote = null;

	/**
	 * @internal vzniká přes ResolutionRecorder (číslo přiděluje NumberingSequenceRepository)
	 */
	public function __construct(VotingRound $round, string $number, int $sequence, string $scopeKey, string $text, \DateTimeImmutable $adoptedAt)
	{
		$this->municipality = $round->getMunicipality();
		$this->meeting = $round->getMeeting();
		$this->body = $round->getMeeting()->getBody();
		$this->agendaItem = $round->getAgendaItem();
		$this->votingRound = $round;
		$this->number = $number;
		$this->sequence = $sequence;
		$this->scopeKey = $scopeKey;
		$this->text = $text;
		$this->adoptedAt = $adoptedAt;
	}

	/**
	 * @internal znění po opraveném hlasování (číslo zůstává)
	 */
	public function changeText(string $text): void
	{
		$this->text = $text;
	}

	/**
	 * Zrušení usnesení pozdějším usnesením (revokace).
	 *
	 * @throws InvalidStateException
	 * @throws ValidationException
	 */
	public function revoke(string $note): void
	{
		if ($this->status !== ResolutionStatus::Adopted) {
			throw new InvalidStateException("Usnesení je {$this->status->label()}, revokovat lze jen přijaté.");
		}

		$note = trim($note);
		if ($note === '' || mb_strlen($note) > 500) {
			throw new ValidationException('Uveďte, kterým usnesením bylo zrušeno (nejvýše 500 znaků).', 'note');
		}

		$this->status = ResolutionStatus::Revoked;
		$this->statusNote = $note;
	}

	public function getStatusNote(): ?string
	{
		return $this->statusNote;
	}

	public function getMunicipality(): Municipality
	{
		return $this->municipality;
	}

	public function getBody(): Body
	{
		return $this->body;
	}

	public function getMeeting(): Meeting
	{
		return $this->meeting;
	}

	public function getAgendaItem(): AgendaItem
	{
		return $this->agendaItem;
	}

	public function getVotingRound(): ?VotingRound
	{
		return $this->votingRound;
	}

	public function getNumber(): string
	{
		return $this->number;
	}

	public function getSequence(): int
	{
		return $this->sequence;
	}

	public function getScopeKey(): string
	{
		return $this->scopeKey;
	}

	public function getText(): string
	{
		return $this->text;
	}

	public function getStatus(): ResolutionStatus
	{
		return $this->status;
	}

	public function getAdoptedAt(): \DateTimeImmutable
	{
		return $this->adoptedAt;
	}
}
