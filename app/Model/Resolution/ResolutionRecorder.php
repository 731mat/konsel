<?php

declare(strict_types=1);

namespace App\Model\Resolution;

use App\Model\Meeting\VotingResult;
use App\Model\Meeting\VotingRound;
use App\Model\Meeting\VotingType;

/**
 * Usnesení z výsledku hlasování: přijatý návrh, protinávrh nebo volba dostane číslo podle masky orgánu.
 * Volá se uvnitř transakce ukončení hlasování.
 */
final readonly class ResolutionRecorder
{
	public function __construct(
		private ResolutionRepository $resolutions,
		private NumberingSequenceRepository $sequences,
	) {}

	/**
	 * @return ?Resolution usnesení k hlasování (null = hlasování usnesení nezakládá nebo nebylo přijato)
	 */
	public function record(VotingRound $round, \DateTimeImmutable $now): ?Resolution
	{
		$existing = $this->resolutions->findByVotingRound($round);
		if ($round->getResult() !== VotingResult::Adopted || !self::foundsResolution($round->getType())) {
			if ($existing !== null) {
				$this->sequences->release($existing->getBody(), $existing->getScopeKey(), $existing->getSequence());
				$this->resolutions->remove($existing);
			}

			return null;
		}

		$text = self::text($round);
		if ($existing !== null) {
			$existing->changeText($text);

			return $existing;
		}

		$meeting = $round->getMeeting();
		$body = $meeting->getBody();
		$scopeKey = $body->numberingScopeKey($meeting->getScheduledAt(), $meeting->getId());
		$sequence = $this->sequences->next($body, $scopeKey);
		$number = $body->getMask()->format($sequence, $meeting->getScheduledAt(), $meeting->getNumber(), $round->getAgendaItem()->getPosition(), $body->getCode());

		$resolution = new Resolution($round, $number, $sequence, $scopeKey, $text, $now);
		$this->resolutions->add($resolution);

		return $resolution;
	}

	public static function foundsResolution(VotingType $type): bool
	{
		return $type !== VotingType::Amendment;
	}

	private static function text(VotingRound $round): string
	{
		$text = $round->getProposalText();
		if ($text !== null) {
			return $text;
		}

		$candidate = $round->getCandidate();

		return sprintf('%s volí %s.', $round->getMeeting()->getBody()->getName(), $candidate?->getFullName() ?? 'kandidáta');
	}
}
