<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Bootstrap;
use Nette\DI\Container;

/**
 * DI kontejner pro integrační testy.
 */
final class ContainerFactory
{
	public static function create(): Container
	{
		return (new Bootstrap())->bootTestContainer();
	}
}
