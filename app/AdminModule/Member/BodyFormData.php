<?php

declare(strict_types=1);

namespace App\AdminModule\Member;

final class BodyFormData
{
	public string $type;

	public string $name;

	public int $seatCount;

	public string $numberingMask;

	public string $numberingScope;
}
