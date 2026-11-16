<?php

declare(strict_types=1);

namespace App\Model\User;

use App\Model\Municipality\Municipality;
use App\Model\Shared\Exception\NotFoundException;
use App\Model\Shared\ValueObject\Email;

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

	public function countActiveAdmins(Municipality $municipality): int;

	public function add(User $user): void;
}
