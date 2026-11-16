<?php

declare(strict_types=1);

namespace App\UI\Form;

use App\Model\Shared\Exception\ValidationException;
use Nette\Forms\Controls\BaseControl;
use Nette\Forms\Form;

/**
 * Převede doménovou ValidationException na chybu formuláře – k poli podle `field`, jinak k formuláři.
 * Konvence: názvy polí formuláře = názvy polí v doméně.
 */
final class FormErrors
{
	public static function apply(Form $form, ValidationException $exception): void
	{
		$control = $exception->field === null ? null : $form->getComponent($exception->field, throw: false);

		if ($control instanceof BaseControl) {
			$control->addError($exception->getMessage());
		} else {
			$form->addError($exception->getMessage());
		}
	}
}
