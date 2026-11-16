<?php

declare(strict_types=1);

namespace App\Model\Comment;

/**
 * Odkaz na autora komentáře podle ID (uživatel úřadu, nebo zastupitel z portálu).
 */
final readonly class CommentAuthorRef
{
	private function __construct(
		public ?int $userId,
		public ?int $personId,
	) {}

	public static function user(int $userId): self
	{
		return new self($userId, null);
	}

	public static function person(int $personId): self
	{
		return new self(null, $personId);
	}
}
