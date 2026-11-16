<?php

declare(strict_types=1);

use Nette\Application\Application;
use Nette\Routing\Router;
use Tester\Assert;
use Tests\Support\ContainerFactory;

require __DIR__ . '/../bootstrap.php';

test('DI container boots and provides core services', function (): void {
	$container = ContainerFactory::create();

	Assert::type(Application::class, $container->getByType(Application::class));
	Assert::type(Router::class, $container->getByType(Router::class));
});
