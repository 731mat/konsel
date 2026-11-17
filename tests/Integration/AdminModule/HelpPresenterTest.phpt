<?php

declare(strict_types=1);

use App\Model\User\Role;
use Nette\Application\BadRequestException;
use Tester\Assert;
use Tests\Support\ContainerFactory;
use Tests\Support\Fixtures;
use Tests\Support\PresenterTester;
use Tests\Support\TestDatabase;

require __DIR__ . '/../../bootstrap.php';

test('wiki pages from doc/wiki are readable for every role, with diagrams and links between pages', function (): void {
	$container = ContainerFactory::create();
	TestDatabase::migrate($container);
	$fixtures = new Fixtures($container);
	$tester = new PresenterTester($container);
	$tester->loginAs($fixtures->user($fixtures->municipality(), role: Role::Reader));

	$index = $tester->render('Admin:Help');
	Assert::contains('Rychlý start', $index);
	Assert::contains('Moduly a jejich propojení', $index, 'seznam kapitol');

	$modules = $tester->render('Admin:Help', 'default', [
		'page' => '03-moduly-a-propojeni',
	]);
	Assert::contains('Úkolníček vs. Úkoly z usnesení', $modules);
	Assert::contains('<code class="language-mermaid">', $modules, 'diagram vykreslí Mermaid v prohlížeči');
	Assert::contains('href="/admin/help?page=04-zivotni-cykly"', $modules, 'odkazy mezi stránkami vedou do aplikace');

	$e = Assert::exception(fn() => $tester->run('Admin:Help', 'default', [
		'page' => '../../config/local',
	]), BadRequestException::class);
	Assert::same(404, $e?->getHttpCode());
});
