<?php

declare(strict_types=1);

namespace App;

use Nette\Bootstrap\Configurator;
use Nette\DI\Container;

final class Bootstrap
{
	private readonly Configurator $configurator;

	private readonly string $rootDir;

	public function __construct()
	{
		$this->rootDir = dirname(__DIR__);
		$this->configurator = new Configurator();
		$this->configurator->setTempDirectory($this->rootDir . '/temp');
	}

	public function bootWebApplication(): Container
	{
		$this->initializeEnvironment();
		$this->setupContainer();

		return $this->configurator->createContainer();
	}

	public function bootConsoleApplication(): Container
	{
		$this->initializeEnvironment();
		$this->setupContainer();

		return $this->configurator->createContainer();
	}

	public function bootTestContainer(): Container
	{
		// Každé vlákno Nette Testeru má vlastní temp, jinak se procesy perou o cache adresáře.
		$thread = getenv('NETTE_TESTER_THREAD');
		$tempDir = $this->rootDir . '/temp/tests/' . ($thread === false ? 'main' : $thread);
		if (!is_dir($tempDir . '/cache')) {
			@mkdir($tempDir . '/cache', 0o777, true);
		}

		$this->configurator->setTempDirectory($tempDir);
		$this->configurator->setDebugMode(false);
		$this->configurator->addStaticParameters([
			'rootDir' => $this->rootDir,
			'varDir' => $tempDir . '/var',
		]);
		$this->setupContainer();

		$testConfig = $this->rootDir . '/config/test.neon';
		if (is_file($testConfig)) {
			$this->configurator->addConfig($testConfig);
		}

		return $this->configurator->createContainer();
	}

	private function initializeEnvironment(): void
	{
		// Debug režim jen explicitně přes NETTE_DEBUG=1 (v Dockeru nefunguje detekce podle IP).
		$this->configurator->setDebugMode(getenv('NETTE_DEBUG') === '1');
		$this->configurator->enableTracy($this->rootDir . '/log');
		$this->configurator->addStaticParameters([
			'rootDir' => $this->rootDir,
			'varDir' => $this->rootDir . '/var',
		]);
	}

	private function setupContainer(): void
	{
		$configDir = $this->rootDir . '/config';
		$this->configurator->addConfig($configDir . '/common.neon');
		$this->configurator->addConfig($configDir . '/doctrine.neon');
		$this->configurator->addConfig($configDir . '/services.neon');

		if (is_file($configDir . '/local.neon')) {
			$this->configurator->addConfig($configDir . '/local.neon');
		}
	}
}
