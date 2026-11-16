<?php

declare(strict_types=1);

namespace App\Application\User\Command;

use App\Domain\User\Role;

final readonly class UpdateUser
{
	public function __construct(
		public string $email,
		public string $name,
		public Role $role,
	) {}
}
