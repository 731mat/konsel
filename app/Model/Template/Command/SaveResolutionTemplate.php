<?php

declare(strict_types=1);

namespace App\Model\Template\Command;

final readonly class SaveResolutionTemplate
{
	public function __construct(
		public string $title,
		public string $category,
		public string $text,
	) {}
}
