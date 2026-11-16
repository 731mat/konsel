<?php

declare(strict_types=1);

namespace App\ErrorModule\Error4xx;

use Nette\Application\Attributes\Requires;
use Nette\Application\BadRequestException;
use Nette\Application\UI\Presenter;

#[Requires(forward: true)]
final class Error4xxPresenter extends Presenter
{
	public function renderDefault(BadRequestException $exception): void
	{
		$code = $exception->getCode();
		$file = __DIR__ . "/{$code}.latte";
		$this->template->httpCode = $code;
		$this->template->setFile(is_file($file) ? $file : __DIR__ . '/4xx.latte');
	}
}
