<?php

declare(strict_types=1);

namespace App\Model\Ai\Command;

final readonly class SaveAiSettings
{
	/**
	 * @param ?string $apiKey nový klíč; null nebo prázdný = ponechat uložený
	 */
	public function __construct(
		public string $model,
		public bool $enabled,
		public ?string $apiKey = null,
	) {}
}
