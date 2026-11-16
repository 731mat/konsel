<?php

declare(strict_types=1);

namespace App\Presentation\Admin\Member;

final class MemberFormData
{
	public int $person;

	public string $function;

	public \DateTimeImmutable $validFrom;

	public ?\DateTimeImmutable $validTo;
}
