<?php

declare(strict_types=1);

use App\Core\Routing\RouterFactory;
use Nette\Http\Request;
use Nette\Http\UrlScript;
use Tester\Assert;

require __DIR__ . '/../../../bootstrap.php';

/**
 * @return array<string, mixed>|null
 */
function matchUrl(string $path): ?array
{
	return RouterFactory::createRouter()->match(new Request(new UrlScript('http://konsel.test' . $path, '/')));
}

test('root URL goes to Public:Homepage:default', function (): void {
	$params = matchUrl('/');

	Assert::same('Public:Homepage', $params['presenter'] ?? null);
	Assert::same('default', $params['action'] ?? null);
});

test('modules have their own URL prefixes', function (): void {
	Assert::same('Public:Sign', matchUrl('/prihlaseni')['presenter'] ?? null);
	Assert::same('Admin:Dashboard', matchUrl('/admin')['presenter'] ?? null);
	Assert::same(['Admin:Meeting', 'detail', '5'], [matchUrl('/admin/meeting/detail/5')['presenter'] ?? null, matchUrl('/admin/meeting/detail/5')['action'] ?? null, matchUrl('/admin/meeting/detail/5')['id'] ?? null]);
	Assert::same('Api:Health', matchUrl('/api/health')['presenter'] ?? null);
});
