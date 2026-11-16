<?php

declare(strict_types=1);

use App\Presentation\Accessory\FormFactory;
use Tester\Assert;

require __DIR__ . '/../../../bootstrap.php';

test('form controls get Bootstrap classes and errors are visible', function (): void {
	$form = (new FormFactory())->create();
	$form->addText('name', 'Název');
	$form->addSelect('type', 'Typ', [
		'a' => 'A',
	]);
	$form->addCheckbox('agree', 'Souhlasím');
	$form->addSubmit('send', 'Uložit');
	$nameControl = $form['name'];
	assert($nameControl instanceof Nette\Forms\Controls\TextInput);
	$nameControl->addError('Vyplňte název.');

	$html = (string) $form;

	Assert::contains('form-control is-invalid', $html);
	Assert::contains('class="form-label"', $html);
	Assert::contains('class="form-select"', $html);
	Assert::contains('class="form-check-input"', $html);
	Assert::contains('class="btn btn-primary', $html);
	Assert::contains('<div class="invalid-feedback d-block">', $html);
	Assert::contains('Vyplňte název.', $html);
});
