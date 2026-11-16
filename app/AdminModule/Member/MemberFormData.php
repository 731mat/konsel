<?php

declare(strict_types=1);

namespace App\AdminModule\Member;

final class MemberFormData
{
	public int $person;

	public string $function;

	public \DateTimeImmutable $validFrom;

	public ?\DateTimeImmutable $validTo;
}
