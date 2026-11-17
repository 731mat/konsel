<?php

declare(strict_types=1);

namespace App\Model\Document;

use Latte\Loader;
use Latte\TemplateNotFoundException;

/**
 * Loader šablon dokumentů: zná jen názvy DocumentTemplateType (žádné cesty k souborům). Vlastní šablona obce
 * má přednost před výchozí z resources/documents. {layout}/{include} tak nedosáhnou nikam jinam.
 */
final readonly class DocumentTemplateLoader implements Loader
{
	/**
	 * @param array<string, string> $overrides název souboru (např. „minutes.latte“) → zdroj vlastní šablony
	 */
	public function __construct(
		private string $directory,
		private array $overrides = [],
	) {}

	public function getContent(string $name): string
	{
		$type = self::type($name);
		if (isset($this->overrides[$type->fileName()])) {
			return $this->overrides[$type->fileName()];
		}

		$content = @file_get_contents($this->directory . '/' . $type->fileName()); // @ – chybějící soubor ohlásí výjimka
		if ($content === false) {
			throw new TemplateNotFoundException("Výchozí šablona '{$type->fileName()}' chybí.");
		}

		return $content;
	}

	public function getReferredName(string $name, string $referringName): string
	{
		return self::type($name)->fileName();
	}

	public function getUniqueId(string $name): string
	{
		return 'konsel-document:' . $name . ':' . hash('xxh128', $this->getContent($name));
	}

	private static function type(string $name): DocumentTemplateType
	{
		$type = substr($name, -6) === '.latte' ? DocumentTemplateType::tryFrom(substr($name, 0, -6)) : null;

		return $type ?? throw new TemplateNotFoundException("Šablona '{$name}' neexistuje – vkládat lze jen šablony dokumentů (např. '@layout.latte').");
	}
}
