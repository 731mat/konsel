<?php

declare(strict_types=1);

namespace App\AdminModule\Meeting;

final class OfficialsFormData
{
	public ?int $chairperson = null;

	public ?int $recorder = null;

	public ?int $firstVerifier = null;

	public ?int $secondVerifier = null;
}
