<?php

declare(strict_types=1);

namespace App\Presentation\Admin\Account;

final class PasswordFormData
{
	public string $currentPassword;

	public string $password;

	public string $passwordConfirm;
}
