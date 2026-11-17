<?php

declare(strict_types=1);

namespace App\Model\Meeting;

use App\Model\Municipality\Municipality;
use App\Model\People\Person;
use App\Model\Shared\Exception\InvalidStateException;
use App\Model\Shared\Exception\ValidationException;
use App\Model\Shared\IntegerId;
use App\Model\Shared\TenantOwned;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Jedno hlasování k bodu programu. Hlasují členové přítomní v okamžiku zahájení hlasování,
 * po ukončení se uloží součty a výsledek podle VotingRule.
 */
#[ORM\Entity]
#[ORM\Table(name: 'voting_round')]
#[ORM\UniqueConstraint(columns: ['agenda_item_id', 'sequence'])]
final class VotingRound implements TenantOwned
{
	use IntegerId;

	public const int MaxTextLength = 5000;

	#[ORM\ManyToOne(targetEntity: Municipality::class)]
	#[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
	private Municipality $municipality;

	#[ORM\ManyToOne(targetEntity: Meeting::class)]
	#[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
	private Meeting $meeting;

	#[ORM\ManyToOne(targetEntity: AgendaItem::class)]
	#[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
	private AgendaItem $agendaItem;

	/**
	 * Pořadí hlasování v rámci bodu (1, 2, …).
	 */
	#[ORM\Column]
	private int $sequence;

	#[ORM\Column(length: 20, enumType: VotingType::class)]
	private VotingType $type;

	#[ORM\Column(type: 'text', nullable: true)]
	private ?string $proposalText;

	#[ORM\ManyToOne(targetEntity: Person::class)]
	#[ORM\JoinColumn(onDelete: 'SET NULL')]
	private ?Person $candidate;

	/**
	 * Okamžik hlasování – rozhoduje o tom, kdo smí hlasovat (docházka).
	 */
	#[ORM\Column]
	private \DateTimeImmutable $openedAt;

	#[ORM\Column(nullable: true)]
	private ?\DateTimeImmutable $closedAt = null;

	#[ORM\Column(length: 20, nullable: true, enumType: VotingResult::class)]
	private ?VotingResult $result = null;

	#[ORM\Column]
	private int $forCount = 0;

	#[ORM\Column]
	private int $againstCount = 0;

	#[ORM\Column]
	private int $abstainCount = 0;

	#[ORM\Column]
	private int $notVotingCount = 0;

	/**
	 * Počet členů orgánu, ze kterého se počítala většina.
	 */
	#[ORM\Column]
	private int $membersCount = 0;

	/**
	 * @var Collection<int, Vote>
	 */
	#[ORM\OneToMany(targetEntity: Vote::class, mappedBy: 'votingRound', cascade: ['persist', 'remove'], orphanRemoval: true)]
	private Collection $votes;

	/**
	 * @param list<VotingRound> $previous dosavadní hlasování k bodu
	 * @throws InvalidStateException
	 * @throws ValidationException
	 */
	public function __construct(
		AgendaItem $item,
		VotingType $type,
		?string $proposalText,
		?Person $candidate,
		Quorum $quorum,
		array $previous,
		\DateTimeImmutable $now,
	) {
		$meeting = $item->getMeeting();
		if ($meeting->getStatus() !== MeetingStatus::InProgress) {
			throw new InvalidStateException('Hlasovat lze jen během zasedání – nejdřív ho zahajte.');
		}

		if (!$quorum->isReached()) {
			throw new InvalidStateException("Orgán není usnášeníschopný (přítomno {$quorum->present} z {$quorum->members}, potřeba {$quorum->required}), hlasovat nelze.");
		}

		foreach ($previous as $round) {
			if ($round->isOpen()) {
				throw new InvalidStateException('U bodu už probíhá hlasování. Nejdřív ho ukončete.');
			}

			if ($type->decidesItem() && $round->type->decidesItem() && $round->result === VotingResult::Adopted) {
				throw new InvalidStateException("O bodu už bylo rozhodnuto přijetím hlasování č. {$round->sequence} ({$round->type->label()}). O dalším návrhu se nehlasuje.");
			}
		}

		$proposalText = $proposalText === null ? null : trim(str_replace(["\r\n", "\r"], "\n", $proposalText));
		if ($proposalText === '') {
			$proposalText = null;
		}

		if ($type === VotingType::Election) {
			if ($candidate === null) {
				throw new ValidationException('Vyberte kandidáta.', 'candidateId');
			}

			if ($candidate->getMunicipality() !== $meeting->getMunicipality()) {
				throw new ValidationException('Kandidát patří k jiné obci.', 'candidateId');
			}
		} elseif ($proposalText === null) {
			throw new ValidationException('Napište znění návrhu, o kterém se hlasuje.', 'proposalText');
		}

		if ($proposalText !== null && mb_strlen($proposalText) > self::MaxTextLength) {
			throw new ValidationException('Znění návrhu může mít nejvýše ' . self::MaxTextLength . ' znaků.', 'proposalText');
		}

		$this->municipality = $meeting->getMunicipality();
		$this->meeting = $meeting;
		$this->agendaItem = $item;
		$this->sequence = count($previous) + 1;
		$this->type = $type;
		$this->proposalText = $proposalText;
		$this->candidate = $type === VotingType::Election ? $candidate : null;
		$this->openedAt = $now;
		$this->votes = new ArrayCollection();
	}

	/**
	 * Zapíše volbu člena. NotVoting hlas odebere (člen nehlasoval).
	 *
	 * @param bool $presentAtVoting byl člen v okamžiku hlasování přítomen?
	 * @throws InvalidStateException
	 * @throws ValidationException
	 */
	public function cast(Person $voter, VoteChoice $choice, bool $presentAtVoting): void
	{
		$this->assertOpen();
		if (!$presentAtVoting) {
			throw new ValidationException("{$voter->getFullName()} nebyl v okamžiku hlasování přítomen, hlasovat nemůže.", 'personId');
		}

		$vote = $this->findVote($voter);
		if ($choice === VoteChoice::NotVoting) {
			if ($vote !== null) {
				$this->votes->removeElement($vote);
			}

			return;
		}

		if ($vote === null) {
			$this->votes->add(new Vote($this, $voter, $choice));
		} else {
			$vote->change($choice);
		}
	}

	/**
	 * Ukončí hlasování: přítomní bez hlasu „nehlasovali“, spočítá se výsledek.
	 *
	 * @param list<Person> $present přítomní v okamžiku hlasování
	 * @throws InvalidStateException
	 */
	public function close(VotingRule $rule, array $present, \DateTimeImmutable $now): VotingResult
	{
		$this->assertOpen();
		foreach ($present as $person) {
			if ($this->findVote($person) === null) {
				$this->votes->add(new Vote($this, $person, VoteChoice::NotVoting));
			}
		}

		$counts = array_fill_keys(array_map(static fn(VoteChoice $c): string => $c->value, VoteChoice::cases()), 0);
		foreach ($this->votes as $vote) {
			$counts[$vote->getChoice()->value]++;
		}

		$this->forCount = $counts[VoteChoice::For->value];
		$this->againstCount = $counts[VoteChoice::Against->value];
		$this->abstainCount = $counts[VoteChoice::Abstain->value];
		$this->notVotingCount = $counts[VoteChoice::NotVoting->value];
		$this->membersCount = $rule->members;
		$this->result = $rule->evaluate($this->forCount);
		$this->closedAt = $now;

		return $this->result;
	}

	/**
	 * Oprava zapsaného hlasování (do ověření zápisu).
	 *
	 * @throws InvalidStateException
	 */
	public function reopen(): void
	{
		if ($this->isOpen()) {
			throw new InvalidStateException('Hlasování ještě probíhá.');
		}

		if (!in_array($this->meeting->getStatus(), [MeetingStatus::InProgress, MeetingStatus::Closed], true)) {
			throw new InvalidStateException('Zápis je ověřen, hlasování už nelze opravit.');
		}

		foreach ($this->votes->toArray() as $vote) {
			if ($vote->getChoice() === VoteChoice::NotVoting) {
				$this->votes->removeElement($vote);
			}
		}

		$this->result = null;
		$this->closedAt = null;
	}

	/**
	 * Smazat lze jen probíhající hlasování (založené omylem).
	 *
	 * @throws InvalidStateException
	 */
	public function assertDeletable(): void
	{
		$this->assertOpen();
	}

	public function isOpen(): bool
	{
		return $this->closedAt === null;
	}

	public function getVoteOf(Person $person): ?VoteChoice
	{
		return $this->findVote($person)?->getChoice();
	}

	/**
	 * Průběžný počet hlasů (u ukončeného hlasování uložené součty).
	 */
	public function count(VoteChoice $choice): int
	{
		if (!$this->isOpen()) {
			return match ($choice) {
				VoteChoice::For => $this->forCount,
				VoteChoice::Against => $this->againstCount,
				VoteChoice::Abstain => $this->abstainCount,
				VoteChoice::NotVoting => $this->notVotingCount,
			};
		}

		return count(array_filter($this->votes->toArray(), static fn(Vote $vote): bool => $vote->getChoice() === $choice));
	}

	/**
	 * @return list<Vote>
	 */
	public function getVotes(): array
	{
		return array_values($this->votes->toArray());
	}

	/**
	 * @return list<Vote> hlasy s danou volbou (pro jmenovité hlasování)
	 */
	public function votesWith(VoteChoice $choice): array
	{
		return array_values(array_filter($this->getVotes(), static fn(Vote $vote): bool => $vote->getChoice() === $choice));
	}

	public function getMunicipality(): Municipality
	{
		return $this->municipality;
	}

	public function getMeeting(): Meeting
	{
		return $this->meeting;
	}

	public function getAgendaItem(): AgendaItem
	{
		return $this->agendaItem;
	}

	public function getSequence(): int
	{
		return $this->sequence;
	}

	public function getType(): VotingType
	{
		return $this->type;
	}

	public function getProposalText(): ?string
	{
		return $this->proposalText;
	}

	public function getCandidate(): ?Person
	{
		return $this->candidate;
	}

	public function getOpenedAt(): \DateTimeImmutable
	{
		return $this->openedAt;
	}

	public function getClosedAt(): ?\DateTimeImmutable
	{
		return $this->closedAt;
	}

	public function getResult(): ?VotingResult
	{
		return $this->result;
	}

	public function getMembersCount(): int
	{
		return $this->membersCount;
	}

	private function findVote(Person $person): ?Vote
	{
		foreach ($this->votes as $vote) {
			if ($vote->getPerson() === $person) {
				return $vote;
			}
		}

		return null;
	}

	private function assertOpen(): void
	{
		if (!$this->isOpen()) {
			throw new InvalidStateException('Hlasování je ukončené. Pro opravu ho znovu otevřete.');
		}
	}
}
