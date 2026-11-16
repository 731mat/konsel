<?php

declare(strict_types=1);

namespace App\Presentation\Sign;

final class SignInFormData
{
	public string $email;

	public string $password;

	public bool $remember;
}
