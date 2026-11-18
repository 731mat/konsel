<?php

declare(strict_types=1);

namespace App\Model\Installation;

/**
 * Jedna kontrola prostředí v průvodci instalací. Blokující chyba instalaci nepustí dál.
 */
final readonly class InstallationCheck
{
	public function __construct(
		public string $label,
		public bool $ok,
		public string $detail,
		public bool $blocking = true,
	) {}
}
