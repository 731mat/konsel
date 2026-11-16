<?php

declare(strict_types=1);

namespace App\Core\Tenancy;

use App\Domain\Municipality\Municipality;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Obec, v jejímž kontextu běží aktuální request nebo příkaz.
 * Nastavením se aktivuje TenantFilter – data jiných obcí pak nejsou vidět.
 */
final class CurrentMunicipality
{
	private ?Municipality $municipality = null;

	public function __construct(
		private readonly EntityManagerInterface $entityManager,
	) {}

	public function set(Municipality $municipality): void
	{
		$this->municipality = $municipality;
		$this->entityManager->getFilters()
			->enable(TenantFilter::Name)
			->setParameter(TenantFilter::Parameter, $municipality->getId(), Types::INTEGER);
	}

	public function clear(): void
	{
		$this->municipality = null;
		if ($this->entityManager->getFilters()->isEnabled(TenantFilter::Name)) {
			$this->entityManager->getFilters()->disable(TenantFilter::Name);
		}
	}

	public function isSet(): bool
	{
		return $this->municipality !== null;
	}

	public function get(): Municipality
	{
		return $this->municipality ?? throw new \LogicException('Current municipality is not set.');
	}
}
