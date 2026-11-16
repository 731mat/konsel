<?php

declare(strict_types=1);

namespace App\Domain\User;

use App\Domain\Municipality\Municipality;
use App\Domain\Shared\Exception\NotFoundException;
use App\Domain\Shared\ValueObject\Email;

interface UserRepository
{
	/**
	 * @throws NotFoundException
	 */
	public function get(int $id): User;

	public function find(int $id): ?User;

	public function findByEmail(Email $email): ?User;

	/**
	 * @return list<User>
	 */
	public function findByMunicipality(Municipality $municipality): array;

	public function add(User $user): void;
}
