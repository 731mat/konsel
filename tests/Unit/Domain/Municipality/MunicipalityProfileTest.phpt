<?php

declare(strict_types=1);

use App\Domain\Municipality\Municipality;
use App\Domain\Municipality\MunicipalityProfile;
use App\Domain\Shared\Exception\ValidationException;
use Tester\Assert;

require __DIR__ . '/../../../bootstrap.php';

test('new municipality gets default official name', function (): void {
	Assert::same('Obec Neplachovice', (new Municipality('Neplachovice', 'neplachovice'))->getProfile()->officialName);
});

test('profile normalizes postal code and website', function (): void {
	$profile = new MunicipalityProfile('Obec Neplachovice', 'Neplachovice 1', 'Neplachovice', '74774', website: 'www.neplachovice.cz');

	Assert::same('747 74', $profile->postalCode);
	Assert::same('https://www.neplachovice.cz', $profile->website);
	Assert::same('Neplachovice 1, 747 74 Neplachovice', $profile->getAddressLine());
});

test('empty optional values become null', function (): void {
	$profile = new MunicipalityProfile('Obec X', ' ', '', null, phone: '  ');

	Assert::null($profile->street);
	Assert::null($profile->city);
	Assert::null($profile->phone);
	Assert::same('', $profile->getAddressLine());
});

test('invalid values are rejected with field name', function (): void {
	$e = Assert::exception(fn() => new MunicipalityProfile('Obec X', postalCode: '1234'), ValidationException::class);
	Assert::same('postalCode', $e?->field);

	$e = Assert::exception(fn() => new MunicipalityProfile('Obec X', website: 'ne platny web'), ValidationException::class);
	Assert::same('website', $e?->field);

	$e = Assert::exception(fn() => new MunicipalityProfile('Obec X', population: -1), ValidationException::class);
	Assert::same('population', $e?->field);

	Assert::exception(fn() => new MunicipalityProfile(' '), ValidationException::class);
});

test('profile round-trips through entity', function (): void {
	$municipality = new Municipality('Neplachovice', 'neplachovice');
	$municipality->updateProfile(new MunicipalityProfile('Obec Neplachovice', city: 'Neplachovice', postalCode: '747 74', population: 650));

	$profile = $municipality->getProfile();
	Assert::same('Neplachovice', $profile->city);
	Assert::same('747 74', $profile->postalCode);
	Assert::same(650, $profile->population);
});
