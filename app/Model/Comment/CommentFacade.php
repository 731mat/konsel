<?php

declare(strict_types=1);

namespace App\Model\Comment;

use App\Model\Attachment\AttachmentPolicy;
use App\Model\Attachment\AttachmentRepository;
use App\Model\Meeting\AgendaItemRepository;
use App\Model\Meeting\Meeting;
use App\Model\People\PersonRepository;
use App\Model\Project\ProjectRepository;
use App\Model\Shared\Exception\AccessDeniedException;
use App\Model\Shared\Exception\InvalidStateException;
use App\Model\Shared\Exception\ValidationException;
use App\Model\Shared\RichText\HtmlSanitizer;
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
		private ProjectRepository $projects,
		private AttachmentRepository $attachments,
		private AttachmentPolicy $policy,
		private FileStorage $storage,
		private TransactionManager $transaction,
		private ClockInterface $clock,
		private HtmlSanitizer $sanitizer,
	) {}

	/**
	 * @param string $body HTML z editoru (pročistí se), nebo prostý text
	 * @param list<UploadedFile> $files soubory přiložené ke komentáři
	 * @throws ValidationException
	 * @throws InvalidStateException
	 */
	public function addByUser(int $itemId, int $userId, string $body, ?int $parentId = null, array $files = []): Comment
	{
		$item = $this->items->get($itemId);
		$body = $this->sanitizer->sanitize($body);

		return $this->create($item->getMunicipality()->getId(), $files, fn(bool $withFiles): Comment => Comment::onAgendaItem(
			$this->items->get($itemId),
			CommentAuthor::user($this->users->get($userId)),
			$body,
			$this->parent($parentId),
			$withFiles,
		));
	}

	/**
	 * Komentář zastupitele z portálu.
	 *
	 * @param list<UploadedFile> $files
	 */
	public function addByPerson(int $itemId, int $personId, string $body, ?int $parentId = null, array $files = []): Comment
	{
		$item = $this->items->get($itemId);
		$body = $this->sanitizer->sanitize($body);

		return $this->create($item->getMunicipality()->getId(), $files, fn(bool $withFiles): Comment => Comment::onAgendaItem(
			$this->items->get($itemId),
			CommentAuthor::person($this->persons->get($personId)),
			$body,
			$this->parent($parentId),
			$withFiles,
		));
	}

	/**
	 * Komentář k plánu.
	 *
	 * @param list<UploadedFile> $files
	 */
	public function addToProject(int $projectId, CommentAuthorRef $author, string $body, ?int $parentId = null, array $files = [], bool $publicForCitizens = false): Comment
	{
		$project = $this->projects->get($projectId);
		$body = $this->sanitizer->sanitize($body);

		return $this->create($project->getMunicipality()->getId(), $files, function (bool $withFiles) use ($projectId, $author, $body, $parentId, $publicForCitizens): Comment {
			$comment = Comment::onProject($this->projects->get($projectId), $this->author($author), $body, $this->parent($parentId), $withFiles);
			$comment->changeVisibility($publicForCitizens);

			return $comment;
		});
	}

	/**
	 * Zveřejnění komentáře k plánu občanům (rozhoduje úřad).
	 */
	public function changeVisibility(int $commentId, bool $publicForCitizens): void
	{
		$this->transaction->run(function () use ($commentId, $publicForCitizens): void {
			$this->comments->get($commentId)->changeVisibility($publicForCitizens);
		});
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
		return self::threads($this->comments->findByAgendaItem($this->items->get($itemId)));
	}

	/**
	 * @param bool $onlyPublic jen komentáře zveřejněné občanům
	 * @return list<CommentThread>
	 */
	public function findProjectThreads(int $projectId, bool $onlyPublic = false): array
	{
		$comments = $this->comments->findByProject($this->projects->get($projectId));
		if ($onlyPublic) {
			$comments = array_values(array_filter($comments, static fn(Comment $c): bool => $c->isPublicForCitizens() && !$c->isDeleted()));
		}

		return self::threads($comments);
	}

	/**
	 * @param list<Comment> $all
	 * @return list<CommentThread>
	 */
	private static function threads(array $all): array
	{
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
	 * @param list<UploadedFile> $files
	 * @param callable(bool): Comment $build
	 */
	private function create(int $municipalityId, array $files, callable $build): Comment
	{
		foreach ($files as $file) {
			$stat = fstat($file->stream);
			$this->policy->assertAllowed($file->originalName, $stat === false ? 0 : $stat['size']);
		}

		$stored = [];
		try {
			foreach ($files as $file) {
				$stored[] = $this->storage->store($file->stream, $file->originalName, "m{$municipalityId}/comments");
			}

			return $this->transaction->run(function () use ($build, $stored): Comment {
				$comment = $build($stored !== []);
				foreach ($stored as $file) {
					$comment->attach($file, $comment->getAuthorUser());
				}

				$this->comments->add($comment);

				return $comment;
			});
		} catch (\Throwable $e) {
			array_walk($stored, fn(StoredFile $file) => $this->storage->delete($file));

			throw $e;
		}
	}

	private function parent(?int $parentId): ?Comment
	{
		return $parentId === null ? null : $this->comments->get($parentId);
	}

	private function author(CommentAuthorRef $ref): CommentAuthor
	{
		return $ref->userId !== null
			? CommentAuthor::user($this->users->get($ref->userId))
			: CommentAuthor::person($this->persons->get($ref->personId ?? 0));
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
