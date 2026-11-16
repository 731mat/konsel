<?php

declare(strict_types=1);

namespace App\Core\Doctrine;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\PhpFilesAdapter;

/**
 * Cache pro Doctrine (metadata, dotazy). Ve vývoji a testech jen v paměti –
 * jinak by změna entity vyžadovala ruční mazání temp/.
 */
final class CacheFactory
{
	public static function create(bool $persistent, string $directory): CacheItemPoolInterface
	{
		return $persistent
			? new PhpFilesAdapter('doctrine', 0, $directory)
			: new ArrayAdapter();
	}
}
