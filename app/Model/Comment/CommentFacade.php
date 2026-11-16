<?php

declare(strict_types=1);

namespace App\Model\Comment;

use App\Model\Meeting\AgendaItemRepository;
use App\Model\Meeting\Meeting;
use App\Model\People\PersonRepository;
use App\Model\Shared\Exception\AccessDeniedException;
use App\Model\Shared\Exception\InvalidStateException;
use App\Model\Shared\Exception\ValidationException;
use App\Model\Shared\TransactionManager;
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
		private TransactionManager $transaction,
		private ClockInterface $clock,
	) {}

	/**
	 * @throws ValidationException
	 * @throws InvalidStateException
	 */
	public function addByUser(int $itemId, int $userId, string $body, ?int $parentId = null): Comment
	{
		return $this->add($itemId, fn(): CommentAuthor => CommentAuthor::user($this->users->get($userId)), $body, $parentId);
	}

	/**
	 * Komentář zastupitele z portálu.
	 */
	public function addByPerson(int $itemId, int $personId, string $body, ?int $parentId = null): Comment
	{
		return $this->add($itemId, fn(): CommentAuthor => CommentAuthor::person($this->persons->get($personId)), $body, $parentId);
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
	 */
	private function add(int $itemId, callable $author, string $body, ?int $parentId): Comment
	{
		return $this->transaction->run(function () use ($itemId, $author, $body, $parentId): Comment {
			$item = $this->items->get($itemId);
			$parent = $parentId === null ? null : $this->comments->get($parentId);
			$comment = new Comment($item, $author(), $body, $parent);
			$this->comments->add($comment);

			return $comment;
		});
	}

	/**
	 * @param callable(): CommentAuthor $author
	 */
	private function delete(int $commentId, callable $author, bool $moderator): void
	{
		$this->transaction->run(function () use ($commentId, $author, $moderator): void {
			$comment = $this->comments->get($commentId);
			if (!$moderator && !$comment->isWrittenBy($author())) {
				throw new AccessDeniedException('Smazat můžete jen vlastní komentář.');
			}

			$comment->delete($this->clock->now());
		});
	}
}
