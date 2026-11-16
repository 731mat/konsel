<?php

declare(strict_types=1);

namespace App\Core\Tenancy;

use App\Domain\Shared\TenantOwned;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query\Filter\SQLFilter;

/**
 * Omezí všechny dotazy na entity TenantOwned na aktuální obec.
 * Zapíná ho CurrentMunicipality::set().
 */
final class TenantFilter extends SQLFilter
{
	public const string Name = 'tenant';

	public const string Parameter = 'municipalityId';

	public function addFilterConstraint(ClassMetadata $targetEntity, string $targetTableAlias): string
	{
		if (!$targetEntity->getReflectionClass()->implementsInterface(TenantOwned::class)) {
			return '';
		}

		return sprintf(
			'%s.%s = %s',
			$targetTableAlias,
			$targetEntity->getSingleAssociationJoinColumnName('municipality'),
			$this->getParameter(self::Parameter),
		);
	}
}
