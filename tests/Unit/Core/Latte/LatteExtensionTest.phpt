<?php

declare(strict_types=1);

use App\Core\Latte\LatteExtension;
use App\Core\Latte\ViteAssets;
use Tester\Assert;

require __DIR__ . '/../../../bootstrap.php';

test('czDate and czDateTime use Czech format', function (): void {
	$date = new DateTimeImmutable('2026-03-05 09:07:00');

	Assert::same('5. 3. 2026', LatteExtension::czDate($date));
	Assert::same('5. 3. 2026 09:07', LatteExtension::czDateTime($date));
	Assert::same('', LatteExtension::czDate(null));
});

test('nl2p splits paragraphs, keeps line breaks and escapes HTML', function (): void {
	$html = (string) LatteExtension::nl2p("První <b>odstavec</b>\nřádek 2\n\n\nDruhý odstavec");

	Assert::same('<p>První &lt;b&gt;odstavec&lt;/b&gt;<br>řádek 2</p><p>Druhý odstavec</p>', $html);
	Assert::same('', (string) LatteExtension::nl2p(null));
});

test('vite renders stylesheet and module script from manifest', function (): void {
	$assets = new ViteAssets(__DIR__ . '/../../../fixtures/vite-manifest.json');

	Assert::same(
		'<link rel="stylesheet" href="/assets/main-def456.css"><script type="module" src="/assets/main-abc123.js"></script>',
		(string) $assets->tags('assets/main.js'),
	);
	Assert::exception(fn() => $assets->tags('missing.js'), RuntimeException::class, '%A%not found in manifest%A%');
});

test('missing manifest gives actionable error', function (): void {
	$assets = new ViteAssets('/nonexistent/manifest.json');

	Assert::exception(fn() => $assets->tags('assets/main.js'), RuntimeException::class, '%A%make assets%A%');
});
