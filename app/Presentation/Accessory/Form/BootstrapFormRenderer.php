<?php

declare(strict_types=1);

namespace App\Presentation\Accessory\Form;

use Nette\Forms\Controls;
use Nette\Forms\Form;
use Nette\Forms\Rendering\DefaultFormRenderer;

/**
 * Vykreslení Nette formulářů v Bootstrap 5 (label nad polem, chyby pod polem).
 */
final class BootstrapFormRenderer extends DefaultFormRenderer
{
	public function __construct()
	{
		$this->wrappers['controls']['container'] = null;
		$this->wrappers['pair']['container'] = 'div class="mb-3"';
		$this->wrappers['pair']['.error'] = 'has-danger';
		$this->wrappers['control']['container'] = null;
		$this->wrappers['label']['container'] = null;
		$this->wrappers['control']['description'] = 'div class="form-text"';
		$this->wrappers['control']['errorcontainer'] = 'div class="invalid-feedback d-block"';
		$this->wrappers['control']['.error'] = 'is-invalid';
		$this->wrappers['error']['container'] = 'div class="alert alert-danger"';
		$this->wrappers['error']['item'] = 'div';
	}

	public function render(Form $form, ?string $mode = null): string
	{
		if ($mode === null || $mode === 'begin') {
			$this->prepare($form);
		}

		return parent::render($form, $mode);
	}

	private function prepare(Form $form): void
	{
		$primaryButtonSet = false;
		foreach ($form->getControls() as $control) {
			if (!$control instanceof Controls\BaseControl) {
				continue;
			}

			$type = $control->getOption('type');

			if ($control instanceof Controls\Button) {
				$hasClass = in_array($control->getControlPrototype()->getAttribute('class'), [null, '', []], true) === false;
				if (!$hasClass) {
					$control->getControlPrototype()->addClass($primaryButtonSet ? 'btn btn-outline-secondary' : 'btn btn-primary');
				}

				$primaryButtonSet = true;
			} elseif ($control instanceof Controls\Checkbox) {
				$control->getControlPrototype()->addClass('form-check-input');
				$control->getContainerPrototype()->setName('div')->addClass('form-check');
				$control->getLabelPrototype()->addClass('form-check-label');
			} elseif ($control instanceof Controls\CheckboxList || $control instanceof Controls\RadioList) {
				$control->getControlPrototype()->addClass('form-check-input');
				$control->getContainerPrototype()->setName('div')->addClass('form-check');
				$control->getItemLabelPrototype()->addClass('form-check-label');
			} elseif ($control instanceof Controls\SelectBox || $control instanceof Controls\MultiSelectBox) {
				$control->getControlPrototype()->addClass('form-select');
				$control->getLabelPrototype()->addClass('form-label');
			} elseif ($type !== 'hidden') {
				$control->getControlPrototype()->addClass('form-control');
				$control->getLabelPrototype()->addClass('form-label');
			}

			if ($control->hasErrors()) {
				$control->getControlPrototype()->addClass('is-invalid');
			}
		}
	}
}
