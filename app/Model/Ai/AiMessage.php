<?php

declare(strict_types=1);

namespace App\Model\Ai;

final readonly class AiMessage
{
	public function __construct(
		public AiRole $role,
		public string $content,
	) {}

	public static function system(string $content): self
	{
		return new self(AiRole::System, $content);
	}

	public static function user(string $content): self
	{
		return new self(AiRole::User, $content);
	}
}
