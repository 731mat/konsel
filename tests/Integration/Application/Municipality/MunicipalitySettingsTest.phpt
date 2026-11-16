<?php

declare(strict_types=1);

use App\Application\Municipality\Command\UpdateMunicipalitySettings;
use App\Application\Municipality\MunicipalityFacade;
use App\Application\Shared\Storage\FileStorage;
use App\Application\Shared\Storage\UploadedFile;
use App\Domain\Municipality\MunicipalityRepository;
use App\Domain\Shared\Exception\ValidationException;
use Doctrine\ORM\EntityManagerInterface;
use Tester\Assert;
use Tests\Support\ContainerFactory;
use Tests\Support\Fixtures;
use Tests\Support\TestDatabase;

require __DIR__ . '/../../../bootstrap.php';

/**
 * @return resource
 */
function pngStream()
{
	$image = imagecreatetruecolor(2, 2);
	assert($image !== false);
	$stream = fopen('php://memory', 'r+b');
	assert($stream !== false);
	imagepng($image, $stream);
	rewind($stream);

	return $stream;
}

/**
 * @return resource
 */
function textStream(string $content)
{
	$stream = fopen('php://memory', 'r+b');
	assert($stream !== false);
	fwrite($stream, $content);
	rewind($stream);

	return $stream;
}

test('settings are saved and normalized', function (): void {
	$container = ContainerFactory::create();
	TestDatabase::migrate($container);
	$municipality = (new Fixtures($container))->municipality();
	$facade = $container->getByType(MunicipalityFacade::class);

	$facade->updateSettings($municipality->getId(), new UpdateMunicipalitySettings(
		name: 'Neplachovice',
		officialName: 'Obec Neplachovice',
		ico: '00064581',
		street: 'Neplachovice 1',
		city: 'Neplachovice',
		postalCode: '74774',
		email: 'OBEC@neplachovice.cz',
		website: 'neplachovice.cz',
		population: 650,
	));

	$container->getByType(EntityManagerInterface::class)->clear();
	$loaded = $container->getByType(MunicipalityRepository::class)->get($municipality->getId());
	$profile = $loaded->getProfile();
	Assert::same('00064581', $loaded->getIco()?->value);
	Assert::same('747 74', $profile->postalCode);
	Assert::same('obec@neplachovice.cz', $profile->email?->value);
	Assert::same('https://neplachovice.cz', $profile->website);
	Assert::same(650, $profile->population);
});

test('IČO used by another municipality is rejected', function (): void {
	$container = ContainerFactory::create();
	TestDatabase::migrate($container);
	$fixtures = new Fixtures($container);
	$facade = $container->getByType(MunicipalityFacade::class);
	$first = $fixtures->municipality('Obec A');
	$second = $fixtures->municipality('Obec B');
	$facade->updateSettings($first->getId(), new UpdateMunicipalitySettings('Obec A', 'Obec A', ico: '00064581'));

	$e = Assert::exception(
		fn() => $facade->updateSettings($second->getId(), new UpdateMunicipalitySettings('Obec B', 'Obec B', ico: '00064581')),
		ValidationException::class,
	);
	Assert::same('ico', $e?->field);
	Assert::noError(fn() => $facade->updateSettings($first->getId(), new UpdateMunicipalitySettings('Obec A', 'Obec A', ico: '00064581')));
});

test('logo is stored, replaced and removed with old files deleted', function (): void {
	$container = ContainerFactory::create();
	TestDatabase::migrate($container);
	$municipality = (new Fixtures($container))->municipality();
	$facade = $container->getByType(MunicipalityFacade::class);
	$storage = $container->getByType(FileStorage::class);

	$facade->changeLogo($municipality->getId(), new UploadedFile(pngStream(), 'znak.png'));
	$first = $municipality->getLogo();
	Assert::notNull($first);
	assert($first !== null);
	Assert::same('image/png', $first->mimeType);
	Assert::match('m%d%/logo/%a%.png', $first->path);

	$facade->changeLogo($municipality->getId(), new UploadedFile(pngStream(), 'znak2.png'));
	Assert::false($storage->exists($first));

	$second = $municipality->getLogo();
	assert($second !== null);
	$facade->removeLogo($municipality->getId());
	Assert::null($municipality->getLogo());
	Assert::false($storage->exists($second));
});

test('non-image logo is rejected and not kept in storage', function (): void {
	$container = ContainerFactory::create();
	TestDatabase::migrate($container);
	$municipality = (new Fixtures($container))->municipality();
	$facade = $container->getByType(MunicipalityFacade::class);

	$e = Assert::exception(
		fn() => $facade->changeLogo($municipality->getId(), new UploadedFile(textStream('<script>alert(1)</script>'), 'logo.png')),
		ValidationException::class,
		'Logo musí být obrázek PNG nebo JPEG.',
	);
	Assert::same('logo', $e?->field);
	Assert::null($municipality->getLogo());
	Assert::same([], (array) glob(App\Bootstrap::testTempDir() . '/var/storage/m' . $municipality->getId() . '/logo/*/*/*'));
});
