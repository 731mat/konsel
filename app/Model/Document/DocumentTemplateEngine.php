<?php

declare(strict_types=1);

namespace App\Model\Document;

use Latte\Sandbox\SecurityPolicy;
use Nette\Bridges\ApplicationLatte\LatteFactory;

/**
 * Vykreslí šablonu dokumentu (výchozí i vlastní šablonu obce) v sandboxu Latte – šablona smí jen číst údaje.
 */
final class DocumentTemplateEngine
{
	private ?SecurityPolicy $policy = null;

	public function __construct(
		private readonly LatteFactory $latteFactory,
		private readonly string $directory = __DIR__ . '/../../../resources/documents',
	) {}

	/**
	 * @param array<string, mixed> $params
	 * @param array<string, string> $overrides název souboru → zdroj vlastní šablony
	 * @throws \Latte\CompileException chyba v zápisu šablony
	 * @throws \Latte\SecurityViolationException šablona použila nepovolenou konstrukci
	 */
	public function render(DocumentTemplateType $type, array $params, array $overrides = []): string
	{
		$latte = $this->latteFactory->create();
		$latte->setLoader(new DocumentTemplateLoader($this->directory, $overrides));
		$latte->setPolicy($this->policy ??= DocumentSandboxPolicy::create());
		$latte->setSandboxMode();

		return $latte->renderToString($type->fileName(), $params);
	}

	/**
	 * Jen překlad šablony (kontrola zápisu a povolených konstrukcí bez dat).
	 *
	 * @param array<string, string> $overrides
	 * @throws \Latte\CompileException
	 * @throws \Latte\SecurityViolationException
	 */
	public function compile(DocumentTemplateType $type, array $overrides): void
	{
		$latte = $this->latteFactory->create();
		$latte->setLoader(new DocumentTemplateLoader($this->directory, $overrides));
		$latte->setPolicy($this->policy ??= DocumentSandboxPolicy::create());
		$latte->setSandboxMode();
		$latte->compile($type->fileName());
	}

	public function getDefaultSource(DocumentTemplateType $type): string
	{
		return (new DocumentTemplateLoader($this->directory))->getContent($type->fileName());
	}
}
