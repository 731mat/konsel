<?php

declare(strict_types=1);

namespace App\Model\Audit;

final readonly class AuditFilter
{
	public function __construct(
		public ?AuditEntityType $type = null,
		public ?int $actorUserId = null,
		public ?\DateTimeImmutable $since = null,
		public ?string $text = null,
	) {}
}
