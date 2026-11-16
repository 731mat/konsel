<?php

declare(strict_types=1);

use App\Core\Security\Allowed;
use Nette\Utils\Finder;
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';

/**
 * Bezpečnostní pojistka: každá action*, render* a handle* metoda v Admin modulu
 * musí mít #[Allowed] (na metodě nebo na třídě presenteru).
 */
test('every admin presenter entry point declares required permission', function (): void {
	$appDir = dirname(__DIR__, 3) . '/app';
	$missing = [];
	$checked = 0;

	foreach (Finder::findFiles('*Presenter.php')->from($appDir . '/Presentation/Admin') as $file) {
		$relative = substr($file->getPathname(), strlen($appDir) + 1, -4);
		$class = 'App\\' . str_replace('/', '\\', $relative);
		Assert::true(class_exists($class), "Class {$class} must be autoloadable");
		/** @var class-string $class */
		$reflection = new ReflectionClass($class);
		if ($reflection->isAbstract()) {
			continue;
		}

		$classAllowed = $reflection->getAttributes(Allowed::class) !== [];
		foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
			if ($method->getDeclaringClass()->getName() !== $class || preg_match('~^(action|render|handle)[A-Z]~', $method->getName()) !== 1) {
				continue;
			}

			$checked++;
			if (!$classAllowed && $method->getAttributes(Allowed::class) === []) {
				$missing[] = "{$class}::{$method->getName()}()";
			}
		}

		if (!$classAllowed && $checked === 0) {
			$missing[] = "{$class} (class without #[Allowed] and without entry points)";
		}
	}

	Assert::same([], $missing);
});
