<?php

declare(strict_types=1);

namespace App\UI\Menu;

use App\Core\Security\Privilege;
use App\Core\Security\Resource;
use Nette\Application\InvalidPresenterException;
use Nette\Application\IPresenterFactory;
use Nette\Application\UI\Control;
use Nette\Bridges\ApplicationLatte\Template;
use Nette\Security\User;

/**
 * Hlavní menu administrace. Zobrazí jen položky, na které má uživatel oprávnění
 * a jejichž presenter už existuje (nové moduly se v menu objeví samy).
 */
final class AdminMenu extends Control
{
	public function __construct(
		private readonly User $user,
		private readonly IPresenterFactory $presenterFactory,
	) {}

	/**
	 * @return list<MenuItem>
	 */
	public static function items(): array
	{
		return [
			new MenuItem('Přehled', 'Dashboard', 'house', Resource::Dashboard),
			new MenuItem('Zasedání', 'Meeting', 'calendar-event', Resource::Meeting),
			new MenuItem('Usnesení', 'Resolution', 'journal-check', Resource::Resolution),
			new MenuItem('Úkoly', 'Task', 'check2-square', Resource::Resolution),
			new MenuItem('Hledat', 'Search', 'search', Resource::Meeting),
			new MenuItem('Osoby a orgány', 'Member', 'people', Resource::Member),
			new MenuItem('Uživatelé', 'User', 'person-gear', Resource::User),
			new MenuItem('Nastavení', 'Settings', 'gear', Resource::Settings),
		];
	}

	public function render(): void
	{
		$template = $this->getTemplate();
		assert($template instanceof Template);
		$template->items = array_values(array_filter(self::items(), $this->isVisible(...)));
		$template->render(__DIR__ . '/AdminMenu.latte');
	}

	private function isVisible(MenuItem $item): bool
	{
		if (!$this->user->isAllowed($item->resource->value, Privilege::View->value)) {
			return false;
		}

		try {
			$name = "Admin:{$item->presenter}";
			$this->presenterFactory->getPresenterClass($name);
		} catch (InvalidPresenterException) {
			return false;
		}

		return true;
	}
}
