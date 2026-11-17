<?php

declare(strict_types=1);

namespace App\AdminModule\Help;

use App\AdminModule\SecuredPresenter;
use App\Core\Security\Allowed;
use App\Core\Security\Resource;
use App\UI\Help\Wiki;
use Latte\Runtime\Html;

/**
 * Nápověda: wiki projektu z doc/wiki (návod, moduly, životní cykly, architektura…).
 */
#[Allowed(Resource::Dashboard)]
final class HelpPresenter extends SecuredPresenter
{
	public function __construct(
		private readonly Wiki $wiki,
	) {
		parent::__construct();
	}

	public function renderDefault(?string $page = null): void
	{
		$pages = $this->wiki->pages();
		$current = $page === null ? ($pages[0] ?? null) : $this->wiki->find($page);
		if ($current === null) {
			$this->error('Stránka nápovědy neexistuje.');
		}

		$this->template->pages = $pages;
		$this->template->current = $current;
		$this->template->html = new Html($this->wiki->render($current, fn(string $slug): string => $this->link('this', [
			'page' => $slug,
		])));
	}
}
