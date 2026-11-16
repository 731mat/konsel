<?php

declare(strict_types=1);

namespace App\Presentation\Accessory;

use App\Presentation\Accessory\Form\BootstrapFormRenderer;
use Nette\Application\UI\Form;

/**
 * Jediné místo, kde vznikají formuláře – společné nastavení a renderer.
 * CSRF: Nette Forms 3.3 ve výchozím stavu odmítá cross-origin odeslání (Sec-Fetch-Site + SameSite cookie).
 */
final class FormFactory
{
	public function create(): Form
	{
		$form = new Form();
		$form->setRenderer(new BootstrapFormRenderer());

		return $form;
	}
}
