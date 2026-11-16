<?php

declare(strict_types=1);

namespace App\Presentation\Admin\Person;

final class PersonFormData
{
	public ?string $titleBefore;

	public string $firstName;

	public string $lastName;

	public ?string $titleAfter;

	public ?string $email;

	public ?string $phone;
}
