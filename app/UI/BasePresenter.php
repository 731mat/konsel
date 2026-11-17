<?php

declare(strict_types=1);

namespace App\UI;

use Nette\Application\UI\Presenter;

/**
 * Předek všech presenterů aplikace.
 */
abstract class BasePresenter extends Presenter
{
	protected function beforeRender(): void
	{
		parent::beforeRender();

		// AJAXový POST (signál) nesmí v historii prohlížeče nechat adresu signálu – obnovení stránky by ho poslalo jako GET.
		if ($this->isAjax() && $this->getHttpRequest()->isMethod('POST')) {
			$this->payload->postGet = true;
			$this->payload->url = $this->link('this');
		}
	}
}
