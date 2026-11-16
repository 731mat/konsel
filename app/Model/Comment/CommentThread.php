<?php

declare(strict_types=1);

namespace App\Model\Comment;

/**
 * Komentář s odpověďmi (pro vykreslení diskuse).
 */
final readonly class CommentThread
{
	/**
	 * @param list<Comment> $replies
	 */
	public function __construct(
		public Comment $comment,
		public array $replies,
	) {}
}
