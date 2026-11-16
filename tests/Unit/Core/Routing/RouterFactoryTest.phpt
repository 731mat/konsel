<?php

declare(strict_types=1);

use App\Core\Routing\RouterFactory;
use Nette\Http\Request;
use Nette\Http\UrlScript;
use Tester\Assert;

require __DIR__ . '/../../../bootstrap.php';

test('router maps root URL to Home:default', function (): void {
	$router = RouterFactory::createRouter();
	$params = $router->match(new Request(new UrlScript('http://konsel.test/', '/')));

	Assert::same('Home', $params['presenter'] ?? null);
	Assert::same('default', $params['action'] ?? null);
});
