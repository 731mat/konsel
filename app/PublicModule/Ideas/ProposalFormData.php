<?php

declare(strict_types=1);

namespace App\PublicModule\Ideas;

final class ProposalFormData
{
	public string $name;

	public string $email;

	public string $title;

	public ?string $description = null;

	public string $website = '';

	public bool $consent;
}
