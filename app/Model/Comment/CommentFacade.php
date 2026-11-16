<?php

declare(strict_types=1);

namespace App\Model\Comment;

use App\Model\Attachment\AttachmentPolicy;
use App\Model\Attachment\AttachmentRepository;
use App\Model\Meeting\AgendaItemRepository;
use App\Model\Meeting\Meeting;
use App\Model\People\PersonRepository;
use App\Model\Shared\Exception\AccessDeniedException;
use App\Model\Shared\Exception\InvalidStateException;
use App\Model\Shared\Exception\ValidationException;
use App\Model\Shared\TransactionManager;
use App\Model\Shared\ValueObject\StoredFile;
use App\Model\Storage\FileStorage;
use App\Model\Storage\UploadedFile;
use App\Model\User\UserRepository;
use Psr\Clock\ClockInterface;

/**
 * Diskuse k bodům programu (úřad v administraci, zastupitelé v portálu).
 */
final readonly class CommentFacade
{
	public function __construct(
		private CommentRepository $comments,
		private AgendaItemRepository $items,
		private UserRepository $users,
		private PersonRepository $persons,
		private AttachmentRepository $attachments,
		private AttachmentPolicy $policy,
		private FileStorage $storage,
		private TransactionManager $transaction,
		private ClockInterface $clock,
	) {}

	/**
	 * @throws ValidationException
	 * @throws InvalidStateException
	 */
	/**
	 * @param list<UploadedFile> $files soubory přiložené ke komentáři
	 */
	public function addByUser(int $itemId, int $userId, string $body, ?int $parentId = null, array $files = []): Comment
	{
		return $this->add($itemId, fn(): CommentAuthor => CommentAuthor::user($this->users->get($userId)), $body, $parentId, $files);
	}

	/**
	 * Komentář zastupitele z portálu.
	 */
	/**
	 * @param list<UploadedFile> $files
	 */
	public function addByPerson(int $itemId, int $personId, string $body, ?int $parentId = null, array $files = []): Comment
	{
		return $this->add($itemId, fn(): CommentAuthor => CommentAuthor::person($this->persons->get($personId)), $body, $parentId, $files);
	}

	/**
	 * @param bool $moderator smí mazat i cizí komentáře (správce)
	 * @throws AccessDeniedException cizí komentář bez práva moderátora
	 */
	public function deleteByUser(int $commentId, int $userId, bool $moderator = false): void
	{
		$this->delete($commentId, fn(): CommentAuthor => CommentAuthor::user($this->users->get($userId)), $moderator);
	}

	public function deleteByPerson(int $commentId, int $personId): void
	{
		$this->delete($commentId, fn(): CommentAuthor => CommentAuthor::person($this->persons->get($personId)), false);
	}

	public function get(int $commentId): Comment
	{
		return $this->comments->get($commentId);
	}

	/**
	 * Diskuse k bodu: vlákna od nejstaršího, odpovědi pod komentářem. Smazané komentáře bez odpovědí se vynechají.
	 *
	 * @return list<CommentThread>
	 */
	public function findThreads(int $itemId): array
	{
		$all = $this->comments->findByAgendaItem($this->items->get($itemId));
		$replies = [];
		foreach ($all as $comment) {
			$parent = $comment->getParent();
			if ($parent !== null && !$comment->isDeleted()) {
				$replies[$parent->getId()][] = $comment;
			}
		}

		$threads = [];
		foreach ($all as $comment) {
			if ($comment->getParent() !== null) {
				continue;
			}

			$commentReplies = $replies[$comment->getId()] ?? [];
			if ($comment->isDeleted() && $commentReplies === []) {
				continue;
			}

			$threads[] = new CommentThread($comment, $commentReplies);
		}

		return $threads;
	}

	/**
	 * @return array<int, int> ID bodu → počet komentářů
	 */
	public function countByMeeting(Meeting $meeting): array
	{
		return $this->comments->countByMeeting($meeting);
	}

	/**
	 * @param callable(): CommentAuthor $author
	 * @param list<UploadedFile> $files
	 */
	private function add(int $itemId, callable $author, string $body, ?int $parentId, array $files): Comment
	{
		$item = $this->items->get($itemId);
		foreach ($files as $file) {
			$stat = fstat($file->stream);
			$this->policy->assertAllowed($file->originalName, $stat === false ? 0 : $stat['size']);
		}

		$stored = [];
		try {
			foreach ($files as $file) {
				$stored[] = $this->storage->store($file->stream, $file->originalName, "m{$item->getMunicipality()->getId()}/comments");
			}

			return $this->transaction->run(function () use ($itemId, $author, $body, $parentId, $stored): Comment {
				$item = $this->items->get($itemId);
				$parent = $parentId === null ? null : $this->comments->get($parentId);
				$commentAuthor = $author();
				$comment = new Comment($item, $commentAuthor, $body, $parent, withFiles: $stored !== []);
				foreach ($stored as $file) {
					$comment->attach($file, $commentAuthor->user);
				}

				$this->comments->add($comment);

				return $comment;
			});
		} catch (\Throwable $e) {
			array_walk($stored, fn(StoredFile $file) => $this->storage->delete($file));

			throw $e;
		}
	}

	/**
	 * @param callable(): CommentAuthor $author
	 */
	private function delete(int $commentId, callable $author, bool $moderator): void
	{
		$files = $this->transaction->run(function () use ($commentId, $author, $moderator): array {
			$comment = $this->comments->get($commentId);
			if (!$moderator && !$comment->isWrittenBy($author())) {
				throw new AccessDeniedException('Smazat můžete jen vlastní komentář.');
			}

			$comment->delete($this->clock->now());
			$files = [];
			foreach ($comment->getAllAttachments() as $attachment) {
				$files[] = $attachment->getFile();
				$this->attachments->remove($attachment);
			}

			return $files;
		});

		array_walk($files, fn(StoredFile $file) => $this->storage->delete($file));
	}
}
