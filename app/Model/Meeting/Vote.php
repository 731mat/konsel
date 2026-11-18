<?php

declare(strict_types=1);

namespace App\Model\Meeting;

use App\Model\People\Person;
use App\Model\Shared\IntegerId;
use Doctrine\ORM\Mapping as ORM;

/**
 * Hlas jednoho člena v hlasování. Patří do agregátu VotingRound.
 */
#[ORM\Entity]
#[ORM\Table(name: 'vote')]
#[ORM\UniqueConstraint(columns: ['voting_round_id', 'person_id'])]
final class Vote
{
	use IntegerId;

	#[ORM\ManyToOne(targetEntity: VotingRound::class, inversedBy: 'votes')]
	#[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
	private VotingRound $votingRound;

	#[ORM\ManyToOne(targetEntity: Person::class)]
	#[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
	private Person $person;

	#[ORM\Column(length: 20, enumType: VoteChoice::class)]
	private VoteChoice $choice;

	public function __construct(VotingRound $votingRound, Person $person, VoteChoice $choice)
	{
		$this->votingRound = $votingRound;
		$this->person = $person;
		$this->choice = $choice;
	}

	public function change(VoteChoice $choice): void
	{
		$this->choice = $choice;
	}

	public function getRound(): VotingRound
	{
		return $this->votingRound;
	}

	public function getPerson(): Person
	{
		return $this->person;
	}

	public function getChoice(): VoteChoice
	{
		return $this->choice;
	}
}
