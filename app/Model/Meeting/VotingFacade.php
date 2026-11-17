<?php

declare(strict_types=1);

namespace App\Model\Meeting;

use App\Model\People\Person;
use App\Model\People\PersonRepository;
use App\Model\Shared\Exception\InvalidStateException;
use App\Model\Shared\Exception\ValidationException;
use App\Model\Shared\TransactionManager;
use Psr\Clock\ClockInterface;

/**
 * Hlasování o bodech programu během zasedání.
 */
final readonly class VotingFacade
{
	public function __construct(
		private AgendaItemRepository $items,
		private MeetingRepository $meetings,
		private PersonRepository $persons,
		private VotingRoundRepository $rounds,
		private ProceedingsFacade $proceedings,
		private TransactionManager $transaction,
		private ClockInterface $clock,
	) {}

	/**
	 * Zahájí hlasování. Bez zadaného textu se u návrhu použije návrh usnesení z bodu.
	 *
	 * @throws InvalidStateException
	 * @throws ValidationException
	 */
	public function open(int $itemId, VotingType $type, ?string $proposalText = null, ?int $candidateId = null): VotingRound
	{
		return $this->transaction->run(function () use ($itemId, $type, $proposalText, $candidateId): VotingRound {
			$item = $this->items->get($itemId);
			$now = $this->clock->now();
			if ($type === VotingType::Proposal && ($proposalText === null || trim($proposalText) === '')) {
				$proposalText = $item->getProposedResolution();
			}

			$round = new VotingRound(
				$item,
				$type,
				$proposalText,
				$candidateId === null ? null : $this->persons->get($candidateId),
				$this->proceedings->attendance($item->getMeeting()->getId(), $now)->quorum,
				$this->rounds->findByItem($item),
				$now,
			);
			$this->rounds->add($round);

			return $round;
		});
	}

	/**
	 * @throws InvalidStateException
	 * @throws ValidationException člen nebyl přítomen
	 */
	public function cast(int $roundId, int $personId, VoteChoice $choice): void
	{
		$this->transaction->run(function () use ($roundId, $personId, $choice): void {
			$round = $this->rounds->get($roundId);
			$person = $this->persons->get($personId);
			$round->cast($person, $choice, in_array($person, $this->voters($round), true));
		});
	}

	/**
	 * Všichni přítomní hlasují pro (výjimky se pak přepnou jednotlivě).
	 *
	 * @throws InvalidStateException
	 */
	public function allFor(int $roundId): void
	{
		$this->transaction->run(function () use ($roundId): void {
			$round = $this->rounds->get($roundId);
			foreach ($this->voters($round) as $person) {
				$round->cast($person, VoteChoice::For, true);
			}
		});
	}

	/**
	 * @throws InvalidStateException
	 */
	public function close(int $roundId): VotingResult
	{
		return $this->transaction->run(function () use ($roundId): VotingResult {
			$round = $this->rounds->get($roundId);

			return $round->close(VotingRule::forBody($round->getMeeting()->getBody()), $this->voters($round), $this->clock->now());
		});
	}

	/**
	 * @throws InvalidStateException
	 */
	public function reopen(int $roundId): void
	{
		$this->transaction->run(function () use ($roundId): void {
			$this->rounds->get($roundId)->reopen();
		});
	}

	/**
	 * @throws InvalidStateException
	 */
	public function delete(int $roundId): void
	{
		$this->transaction->run(function () use ($roundId): void {
			$round = $this->rounds->get($roundId);
			$round->assertDeletable();
			$this->rounds->remove($round);
		});
	}

	public function get(int $roundId): VotingRound
	{
		return $this->rounds->get($roundId);
	}

	/**
	 * @return array<int, list<VotingRound>> ID bodu → hlasování
	 */
	public function findByMeeting(int $meetingId): array
	{
		$grouped = [];
		foreach ($this->rounds->findByMeeting($this->meetings->get($meetingId)) as $round) {
			$grouped[$round->getAgendaItem()->getId()][] = $round;
		}

		return $grouped;
	}

	/**
	 * Kdo smí v daném hlasování hlasovat (přítomní v okamžiku hlasování).
	 *
	 * @return list<Person>
	 */
	public function voters(VotingRound $round): array
	{
		return array_map(
			static fn(AttendanceEntry $entry): Person => $entry->person,
			$this->proceedings->attendance($round->getMeeting()->getId(), $round->getOpenedAt())->present($round->getOpenedAt()),
		);
	}
}
