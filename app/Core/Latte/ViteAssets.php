<?php

declare(strict_types=1);

namespace App\Core\Latte;

use Nette\Utils\FileSystem;
use Nette\Utils\Html;
use Nette\Utils\Json;

/**
 * Vloží CSS a JS vstupního bodu podle Vite manifestu (www/assets/.vite/manifest.json).
 */
final class ViteAssets
{
	/**
	 * @var array<string, array{file: string, css?: list<string>}>|null
	 */
	private ?array $manifest = null;

	public function __construct(
		private readonly string $manifestPath,
		private readonly string $basePath = '/assets/',
	) {}

	public function tags(string $entry): Html
	{
		$chunk = $this->getManifest()[$entry] ?? throw new \RuntimeException("Vite entry '{$entry}' not found in manifest. Run `make assets`.");

		$html = Html::el();
		foreach ($chunk['css'] ?? [] as $css) {
			$html->addHtml(Html::el('link', [
				'rel' => 'stylesheet',
				'href' => $this->basePath . $css,
			]));
		}

		$html->addHtml(Html::el('script', [
			'type' => 'module',
			'src' => $this->basePath . $chunk['file'],
		]));

		return $html;
	}

	/**
	 * @return array<string, array{file: string, css?: list<string>}>
	 */
	private function getManifest(): array
	{
		if ($this->manifest === null) {
			if (!is_file($this->manifestPath)) {
				throw new \RuntimeException("Vite manifest '{$this->manifestPath}' is missing. Run `make assets`.");
			}

			/** @var array<string, array{file: string, css?: list<string>}> $manifest */
			$manifest = Json::decode(FileSystem::read($this->manifestPath), forceArrays: true);
			$this->manifest = $manifest;
		}

		return $this->manifest;
	}
}
