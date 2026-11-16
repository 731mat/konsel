<?php

declare(strict_types=1);

use Nette\Application\BadRequestException;
use Nette\Application\IPresenterFactory;
use Nette\Application\Request;
use Nette\Application\UI\Presenter;
use Tester\Assert;
use Tests\Support\ContainerFactory;
use Tests\Support\PresenterTester;

require __DIR__ . '/../../bootstrap.php';

/**
 * V produkci (bez Tracy) obslouží chyby 4xx ErrorModule – ověří mapování modulu i šablony.
 */
test('error presenter renders 404 page with link to homepage', function (): void {
	$container = ContainerFactory::create();
	$presenter = $container->getByType(IPresenterFactory::class)->createPresenter('Error:Error4xx');
	assert($presenter instanceof Presenter);
	$presenter->autoCanonicalize = false;

	$response = $presenter->run(new Request('Error:Error4xx', Request::Forward, [
		'exception' => new BadRequestException('Not found', 404),
	]));
	$html = PresenterTester::renderResponse($response);

	Assert::contains('Stránka nenalezena', $html);
	Assert::contains('href="/"', $html);
});
