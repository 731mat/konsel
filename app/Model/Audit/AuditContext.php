<?php

declare(strict_types=1);

namespace App\Model\Audit;

/**
 * Kdo v tomto požadavku pracuje – nastaví presenter po přihlášení (úřad) nebo po vstupu odkazem (portál).
 */
final class AuditContext
{
	private ?AuditActor $actor = null;

	public function setActor(AuditActor $actor): void
	{
		$this->actor = $actor;
	}

	public function getActor(): AuditActor
	{
		return $this->actor ?? AuditActor::system();
	}
}
