<?php

declare(strict_types=1);

namespace App\Model\Mail;

use App\Model\Municipality\Municipality;
use App\UI\Theme\BrandStyle;
use Nette\Bridges\ApplicationLatte\LatteFactory;

/**
 * HTML e-mailů z Latte šablon v resources/mail (inline styly, barvy obce).
 */
final readonly class MailTemplates
{
	public function __construct(
		private LatteFactory $latteFactory,
		private string $templateDir = __DIR__ . '/../../../resources/mail',
	) {}

	/**
	 * @param array<string, mixed> $params
	 */
	public function render(string $template, Municipality $municipality, array $params): string
	{
		$colors = $municipality->getBrandColors();

		return $this->latteFactory->create()->renderToString("{$this->templateDir}/{$template}.latte", $params + [
			'municipality' => $municipality,
			'profile' => $municipality->getProfile(),
			'brand' => $colors->primary->value,
			'onBrand' => BrandStyle::onColor($colors->primary)->value,
			'accent' => $colors->accent->value,
		]);
	}
}
