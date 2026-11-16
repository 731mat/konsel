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

		$router->withModule('Api')
			->addRoute('api/<presenter>[/<action>[/<id \d+>]]', 'Health:default');

		$router->withModule('Admin')
			->addRoute('admin[/<presenter>[/<action>[/<id \d+>]]]', 'Dashboard:default');

		$router->withModule('Public')
			->addRoute('prihlaseni', 'Sign:in')
			->addRoute('odhlaseni', 'Sign:out')
			->addRoute('<presenter>/<action>[/<id>]', 'Homepage:default');

		return $router;
	}
}
