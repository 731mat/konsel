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

		// Server MCP pro AI asistenty a přihlášení aplikací přes OAuth (souhlas uživatele je v administraci)
		$router->withModule('Api')
			->addRoute('mcp', 'Mcp:default')
			->addRoute('.well-known/oauth-protected-resource[/<resource .+>]', 'OAuth:protectedResource')
			->addRoute('.well-known/oauth-authorization-server[/<issuer .+>]', 'OAuth:metadata')
			->addRoute('oauth/<action register|token|revoke>', 'OAuth:default')
			->addRoute('api/<presenter>[/<action>[/<id \d+>]]', 'Health:default');

		$router->withModule('Admin')
			->addRoute('oauth/authorize', 'AiConnection:authorize');

		$router->withModule('Admin')
			->addRoute('admin[/<presenter>[/<action>[/<id \d+>]]]', 'Dashboard:default');

		$router->withModule('Portal')
			->addRoute('odkaz/<token [A-Za-z0-9_-]{43}>', 'Entry:default')
			->addRoute('portal[/<presenter>[/<action>[/<id \d+>]]]', 'Meeting:default');

		$router->withModule('Public')
			->addRoute('obec/<municipality [a-z0-9-]+>/podnety', 'Ideas:default')
			->addRoute('obec/<municipality [a-z0-9-]+>/podnety/<id \d+>', 'Ideas:detail')
			->addRoute('obec/<municipality [a-z0-9-]+>/podnety/soubor/<id \d+>', 'Ideas:file')
			->addRoute('prihlaseni', 'Sign:in')
			->addRoute('odhlaseni', 'Sign:out')
			->addRoute('<presenter>/<action>[/<id>]', 'Homepage:default');

		return $router;
	}
}
