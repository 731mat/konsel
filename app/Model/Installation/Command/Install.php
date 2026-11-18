<?php

declare(strict_types=1);

namespace App\Model\Installation\Command;

use App\Model\Municipality\Module;

/**
 * Vše z průvodce instalací – založí se najednou v jedné transakci.
 */
final readonly class Install
{
	/**
	 * @param list<Module> $modules zapnuté moduly
	 */
	public function __construct(
		public string $municipalityName,
		public string $officialName,
		public ?string $ico,
		public ?string $street,
		public ?string $city,
		public ?string $postalCode,
		public ?string $email,
		public string $termName,
		public \DateTimeImmutable $termStartsOn,
		public string $councilName,
		public int $councilSeats,
		public ?string $boardName,
		public ?int $boardSeats,
		public string $adminName,
		public string $adminEmail,
		#[\SensitiveParameter]
		public string $adminPassword,
		public array $modules,
		public bool $installTemplates,
		public bool $dailyBackup,
	) {}
}
