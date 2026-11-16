<?php

declare(strict_types=1);

namespace App\Core\Routing;

use Nette\Application\Routers\RouteList;
use Nette\StaticClass;

final class RouterFactory
{
	use StaticClass;

	public static function createRouter(): RouteList
	{
		$router = new RouteList();
		$router->addRoute('prihlaseni', 'Sign:in');
		$router->addRoute('odhlaseni', 'Sign:out');

		$router->withModule('Admin')
			->addRoute('admin[/<presenter>[/<action>[/<id \d+>]]]', 'Dashboard:default');

		$router->addRoute('<presenter>/<action>[/<id>]', 'Home:default');

		return $router;
	}
}
