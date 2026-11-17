<?php

declare(strict_types=1);

namespace App\Model\Resolution;

use App\Model\Meeting\AgendaItem;
use App\Model\Meeting\Meeting;
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
				// pořadové číslo uvolnit, jen když ho nesdílí jiné usnesení téhož bodu (maska s {sub})
				if ($this->sharingSequence($existing) === []) {
					$this->sequences->release($existing->getBody(), $existing->getScopeKey(), $existing->getSequence());
				}

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
		$mask = $body->getMask();
		$item = $round->getAgendaItem();
		$group = $item->getParent() ?? $item;
		$scopeKey = $body->numberingScopeKey($meeting->getScheduledAt(), $meeting->getId());

		$sequence = null;
		$sub = 0;
		if ($mask->usesSub()) {
			// usnesení bodu a jeho podbodů sdílejí pořadové číslo, liší se číslem podbodu
			$sub = $item->getSubNumber() ?? 0;
			$used = [];
			foreach ($this->groupResolutions($meeting, $group) as $other) {
				if ($other->getScopeKey() === $scopeKey) {
					$sequence = $other->getSequence();
					$used[] = $other->getSubNumber();
				}
			}

			// více usnesení k bodu bez podbodů (např. volba více osob) – další dostane pořadí podbodu
			while (in_array($sub, $used, true)) {
				$sub = max($used) + 1;
			}
		}

		$sequence ??= $this->sequences->next($body, $scopeKey);
		$number = $mask->format($sequence, $meeting->getScheduledAt(), $meeting->getNumber(), $group->getTopNumber(), $body->getCode(), $sub === 0 ? null : $sub);

		$resolution = new Resolution($round, $number, $sequence, $scopeKey, $text, $now, $sub);
		$this->resolutions->add($resolution);

		return $resolution;
	}

	/**
	 * @return list<Resolution> usnesení k bodu a jeho podbodům
	 */
	private function groupResolutions(Meeting $meeting, AgendaItem $group): array
	{
		return array_values(array_filter(
			$this->resolutions->findByMeeting($meeting),
			static fn(Resolution $resolution): bool => ($resolution->getAgendaItem()->getParent() ?? $resolution->getAgendaItem()) === $group,
		));
	}

	/**
	 * @return list<Resolution> jiná usnesení se stejným pořadovým číslem
	 */
	private function sharingSequence(Resolution $resolution): array
	{
		return array_values(array_filter(
			$this->resolutions->findByMeeting($resolution->getMeeting()),
			static fn(Resolution $other): bool => $other !== $resolution && $other->getScopeKey() === $resolution->getScopeKey() && $other->getSequence() === $resolution->getSequence(),
		));
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
