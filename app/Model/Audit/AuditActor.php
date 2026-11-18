<?php

declare(strict_types=1);

namespace App\Model\Audit;

/**
 * Kdo změnu provedl: uživatel úřadu, zastupitel z portálu, nebo systém (konzole, demo data).
 */
final readonly class AuditActor
{
	public function __construct(
		public string $name,
		public ?int $userId = null,
		public ?string $ip = null,
	) {}

	public static function system(): self
	{
		return new self('systém');
	}
}
