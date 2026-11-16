<?php

declare(strict_types=1);

use App\Core\Tenancy\TenantFilter;
use App\Domain\Municipality\Municipality;
use App\Domain\Shared\TenantOwned;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\Persistence\Mapping\RuntimeReflectionService;
use Tester\Assert;
use Tests\Support\ContainerFactory;

require __DIR__ . '/../../../bootstrap.php';

final class TenantOwnedFixture implements TenantOwned
{
	public function getMunicipality(): Municipality
	{
		return new Municipality('Obec', 'obec');
	}
}

/**
 * @param class-string $class
 * @return ClassMetadata<object>
 */
function metadataFor(string $class, bool $withMunicipality): ClassMetadata
{
	$metadata = new ClassMetadata($class);
	$metadata->initializeReflection(new RuntimeReflectionService());
	if ($withMunicipality) {
		$metadata->mapManyToOne([
			'fieldName' => 'municipality',
			'targetEntity' => Municipality::class,
			'joinColumns' => [[
				'name' => 'municipality_id',
				'referencedColumnName' => 'id',
			]],
		]);
	}

	return $metadata;
}

test('filter restricts tenant-owned entities to current municipality', function (): void {
	$entityManager = ContainerFactory::create()->getByType(EntityManagerInterface::class);
	$filter = new TenantFilter($entityManager);
	$filter->setParameter(TenantFilter::Parameter, 42, 'integer');

	Assert::same("t0.municipality_id = '42'", $filter->addFilterConstraint(metadataFor(TenantOwnedFixture::class, true), 't0'));
});

test('filter ignores entities that are not tenant-owned', function (): void {
	$entityManager = ContainerFactory::create()->getByType(EntityManagerInterface::class);
	$filter = new TenantFilter($entityManager);

	Assert::same('', $filter->addFilterConstraint(metadataFor(Municipality::class, false), 't0'));
});
