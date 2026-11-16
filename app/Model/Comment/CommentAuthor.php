<?php

declare(strict_types=1);

namespace App\Model\Comment;

use App\Model\Municipality\Municipality;
use App\Model\People\Person;
use App\Model\User\User;

/**
 * Kdo komentuje: uživatel úřadu, nebo zastupitel (Person) přihlášený odkazem z pozvánky.
 */
final readonly class CommentAuthor
{
	private function __construct(
		public ?User $user,
		public ?Person $person,
	) {}

	public static function user(User $user): self
	{
		return new self($user, null);
	}

	public static function person(Person $person): self
	{
		return new self(null, $person);
	}

	public function name(): string
	{
		return $this->user?->getName() ?? $this->person?->getFullName() ?? '';
	}

	public function municipality(): ?Municipality
	{
		return $this->user?->getMunicipality() ?? $this->person?->getMunicipality();
	}
}
