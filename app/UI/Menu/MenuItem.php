<?php

declare(strict_types=1);

namespace App\UI\Menu;

use App\Core\Security\Resource;

final readonly class MenuItem
{
	public function __construct(
		public string $label,
		/**
		 * Presenter v Admin modulu, např. "Meeting"
		 */
		public string $presenter,
		/**
		 * Bootstrap Icons třída bez prefixu "bi-"
		 */
		public string $icon,
		public Resource $resource,
		/**
		 * Nadpis skupiny v menu
		 */
		public string $section = 'Jednání',
	) {}

	public function destination(): string
	{
		return ":Admin:{$this->presenter}:";
	}

	public function linkMask(): string
	{
		return ":Admin:{$this->presenter}:*";
	}
}
