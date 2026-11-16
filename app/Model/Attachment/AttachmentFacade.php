<?php

declare(strict_types=1);

namespace App\Model\Attachment;

use App\Model\Meeting\AgendaItem;
use App\Model\Meeting\AgendaItemRepository;
use App\Model\Meeting\Meeting;
use App\Model\Shared\Exception\InvalidStateException;
use App\Model\Shared\Exception\ValidationException;
use App\Model\Shared\TransactionManager;
use App\Model\Shared\ValueObject\StoredFile;
use App\Model\Storage\FileStorage;
use App\Model\Storage\UploadedFile;
use App\Model\User\UserRepository;

/**
 * Přílohy k bodům programu. Soubory se ukládají před transakcí a při chybě se uklidí,
 * mazání souboru proběhne až po úspěšném commitu.
 */
final readonly class AttachmentFacade
{
	public function __construct(
		private AttachmentRepository $attachments,
		private AgendaItemRepository $items,
		private UserRepository $users,
		private FileStorage $storage,
		private AttachmentPolicy $policy,
		private TransactionManager $transaction,
	) {}

	/**
	 * @param non-empty-list<UploadedFile> $files
	 * @return list<Attachment>
	 * @throws ValidationException nepovolený typ nebo velikost – nenahraje se nic
	 * @throws InvalidStateException zasedání je uzavřené
	 */
	public function upload(int $itemId, array $files, ?int $userId, bool $publicForMembers = true): array
	{
		$item = $this->items->get($itemId);
		$this->assertEditable($item);
		foreach ($files as $file) {
			$this->policy->assertAllowed($file->originalName, self::size($file));
		}

		$stored = [];
		try {
			foreach ($files as $file) {
				$stored[] = $this->storage->store($file->stream, $file->originalName, "m{$item->getMunicipality()->getId()}/attachments");
			}

			return $this->transaction->run(function () use ($itemId, $stored, $userId, $publicForMembers): array {
				$item = $this->items->get($itemId);
				$uploadedBy = $userId === null ? null : $this->users->get($userId);

				return array_map(function (StoredFile $file) use ($item, $uploadedBy, $publicForMembers): Attachment {
					$attachment = new Attachment($item, $file, $uploadedBy, $publicForMembers);
					$this->attachments->add($attachment);

					return $attachment;
				}, $stored);
			});
		} catch (\Throwable $e) {
			array_walk($stored, $this->storage->delete(...));

			throw $e;
		}
	}

	public function delete(int $attachmentId): void
	{
		$file = $this->transaction->run(function () use ($attachmentId): StoredFile {
			$attachment = $this->attachments->get($attachmentId);
			$this->assertEditable($attachment->getAgendaItem());
			$this->attachments->remove($attachment);

			return $attachment->getFile();
		});

		$this->storage->delete($file);
	}

	public function changeVisibility(int $attachmentId, bool $publicForMembers): void
	{
		$this->transaction->run(function () use ($attachmentId, $publicForMembers): void {
			$attachment = $this->attachments->get($attachmentId);
			$this->assertEditable($attachment->getAgendaItem());
			$attachment->changeVisibility($publicForMembers);
		});
	}

	public function get(int $attachmentId): Attachment
	{
		return $this->attachments->get($attachmentId);
	}

	/**
	 * @return list<Attachment>
	 */
	public function findByAgendaItem(int $itemId): array
	{
		return $this->attachments->findByAgendaItem($this->items->get($itemId));
	}

	/**
	 * @return array<int, int> ID bodu → počet příloh
	 */
	public function countByMeeting(Meeting $meeting): array
	{
		return $this->attachments->countByMeeting($meeting);
	}

	public function getLocalPath(Attachment $attachment): string
	{
		return $this->storage->getLocalPath($attachment->getFile());
	}

	private function assertEditable(AgendaItem $item): void
	{
		if (!$item->getMeeting()->canEditAttachments()) {
			throw new InvalidStateException('Zasedání je uzavřené (zápis ověřen), přílohy už nelze měnit.');
		}
	}

	private static function size(UploadedFile $file): int
	{
		$stat = fstat($file->stream);

		return $stat === false ? 0 : $stat['size'];
	}
}
