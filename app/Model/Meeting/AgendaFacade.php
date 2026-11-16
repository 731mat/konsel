<?php

declare(strict_types=1);

namespace App\Model\Meeting;

use App\Model\Attachment\AttachmentRepository;
use App\Model\Meeting\Command\SaveAgendaItem;
use App\Model\People\PersonRepository;
use App\Model\Shared\Exception\InvalidStateException;
use App\Model\Shared\Exception\ValidationException;
use App\Model\Shared\TransactionManager;
use App\Model\Shared\ValueObject\StoredFile;
use App\Model\Storage\FileStorage;

/**
 * Program zasedání: body, pořadí a standardní body.
 */
final readonly class AgendaFacade
{
	public function __construct(
		private MeetingRepository $meetings,
		private AgendaItemRepository $items,
		private PersonRepository $persons,
		private AttachmentRepository $attachments,
		private FileStorage $storage,
		private TransactionManager $transaction,
	) {}

	/**
	 * @throws InvalidStateException zasedání už začalo
	 * @throws ValidationException
	 */
	public function addItem(int $meetingId, SaveAgendaItem $command): AgendaItem
	{
		return $this->transaction->run(fn(): AgendaItem => $this->meetings->get($meetingId)->addAgendaItem($this->content($command)));
	}

	public function updateItem(int $itemId, SaveAgendaItem $command): void
	{
		$this->transaction->run(function () use ($itemId, $command): void {
			$item = $this->items->get($itemId);
			$item->getMeeting()->updateAgendaItem($item, $this->content($command));
		});
	}

	/**
	 * Odstraní bod včetně příloh (soubory se smažou až po commitu).
	 */
	public function removeItem(int $itemId): void
	{
		$files = $this->transaction->run(function () use ($itemId): array {
			$item = $this->items->get($itemId);
			$files = [];
			foreach ($this->attachments->findByAgendaItem($item) as $attachment) {
				$files[] = $attachment->getFile();
				$this->attachments->remove($attachment);
			}

			$item->getMeeting()->removeAgendaItem($item);

			return $files;
		});

		array_walk($files, fn(StoredFile $file) => $this->storage->delete($file));
	}

	public function moveItem(int $itemId, int $position): void
	{
		$this->transaction->run(function () use ($itemId, $position): void {
			$item = $this->items->get($itemId);
			$item->getMeeting()->moveAgendaItem($item, $position);
		});
	}

	/**
	 * @param list<int> $itemIds nové pořadí všech bodů
	 */
	public function reorder(int $meetingId, array $itemIds): void
	{
		$this->transaction->run(function () use ($meetingId, $itemIds): void {
			$this->meetings->get($meetingId)->reorderAgenda($itemIds);
		});
	}

	/**
	 * Doplní standardní úvodní (zahájení, ověřovatelé, program) a závěrečné body (diskuse, závěr).
	 * Body se stejným názvem, které už v programu jsou, přeskočí.
	 *
	 * @return int počet přidaných bodů
	 */
	public function insertStandardItems(int $meetingId): int
	{
		return $this->transaction->run(function () use ($meetingId): int {
			$meeting = $this->meetings->get($meetingId);
			$existing = array_map(static fn(AgendaItem $item): string => mb_strtolower($item->getTitle()), $meeting->getAgendaItems());
			$added = 0;

			$position = 1;
			foreach (StandardAgenda::opening($meeting) as $content) {
				if (!in_array(mb_strtolower($content->title), $existing, true)) {
					$meeting->addAgendaItem($content, $position++);
					$added++;
				}
			}

			foreach (StandardAgenda::closing($meeting) as $content) {
				if (!in_array(mb_strtolower($content->title), $existing, true)) {
					$meeting->addAgendaItem($content);
					$added++;
				}
			}

			return $added;
		});
	}

	public function getItem(int $itemId): AgendaItem
	{
		return $this->items->get($itemId);
	}

	private function content(SaveAgendaItem $command): AgendaItemContent
	{
		return new AgendaItemContent(
			$command->title,
			$command->description,
			$command->proposedResolution,
			$command->rapporteurId === null ? null : $this->persons->get($command->rapporteurId),
		);
	}
}
