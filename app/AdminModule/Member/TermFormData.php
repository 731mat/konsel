<?php

declare(strict_types=1);

namespace App\AdminModule\Member;

final class TermFormData
{
	public string $name;

	public \DateTimeImmutable $startsOn;

	public ?\DateTimeImmutable $endsOn;
}
