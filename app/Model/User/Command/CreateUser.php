<?php

declare(strict_types=1);

namespace App\Model\User\Command;

use App\Model\User\Role;

final readonly class CreateUser
{
	public function __construct(
		public string $email,
		public string $name,
		public Role $role,
		public string $password,
		public ?int $municipalityId = null,
		public bool $mustChangePassword = false,
	) {}
}
