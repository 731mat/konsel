<?php

declare(strict_types=1);

namespace App\Core\Tenancy;

use Doctrine\ORM\EntityManagerInterface;

/**
 * Dotaz napříč obcemi (ověření tokenu dřív, než je známá obec). Filtr se pozastaví a obnoví i s parametrem.
 */
final class TenantFilterSuspender
{
	/**
	 * @template T
	 * @param callable(): T $query
	 * @return T
	 */
	public static function run(EntityManagerInterface $entityManager, callable $query): mixed
	{
		$filters = $entityManager->getFilters();
		$enabled = $filters->isEnabled(TenantFilter::Name);
		if ($enabled) {
			$filters->suspend(TenantFilter::Name);
		}

		try {
			return $query();
		} finally {
			if ($enabled) {
				$filters->restore(TenantFilter::Name);
			}
		}
	}
}
