<?php

declare(strict_types=1);

use App\Model\Template\TemplateFacade;
use App\Model\User\Role;
use Nette\Application\BadRequestException;
use Tester\Assert;
use Tests\Support\ContainerFactory;
use Tests\Support\Fixtures;
use Tests\Support\MeetingScenario;
use Tests\Support\PresenterTester;
use Tests\Support\TestDatabase;

require __DIR__ . '/../../bootstrap.php';

test('editor installs defaults, adds template and uses it in agenda item form', function (): void {
	$container = ContainerFactory::create();
	TestDatabase::migrate($container);
	$scenario = MeetingScenario::create($container);
	$tester = new PresenterTester($container);
	$tester->loginAs((new Fixtures($container))->user($scenario->municipality));

	PresenterTester::assertRedirect($tester->run('Admin:Template', 'default', [
		'do' => 'installDefaults',
	], post: [
		'x' => '1',
	]));
	PresenterTester::assertRedirect($tester->submitForm('Admin:Template', 'resolutionTemplateForm', [
		'title' => 'Prodloužení nájmu hospody',
		'category' => 'Majetek',
		'text' => '{organ} schvaluje prodloužení nájemní smlouvy na obecní hospodu do roku {rok}.',
	], action: 'add'));

	$html = $tester->render('Admin:Template');
	Assert::contains('Prodloužení nájmu hospody', $html);
	Assert::contains('Ustavující zasedání – s radou', $html);

	$form = $tester->render('Admin:Agenda', 'add', [
		'meeting' => $scenario->meeting->getId(),
	]);
	Assert::contains('data-text="Zastupitelstvo obce Neplachovice schvaluje prodloužení nájemní smlouvy na obecní hospodu do roku 2026."', $form);

	$meetingTemplate = $container->getByType(TemplateFacade::class)->findMeetingTemplates($scenario->municipality->getId())[0];
	PresenterTester::assertRedirect($tester->run('Admin:Meeting', 'detail', [
		'id' => $scenario->meeting->getId(),
		'do' => 'applyTemplate',
	], post: [
		'templateId' => (string) $meetingTemplate->getId(),
	]));
	Assert::contains('Program ze šablony', $tester->render('Admin:Meeting', 'detail', [
		'id' => $scenario->meeting->getId(),
	]));
});

test('reader cannot edit templates', function (): void {
	$container = ContainerFactory::create();
	TestDatabase::migrate($container);
	$scenario = MeetingScenario::create($container);
	$tester = new PresenterTester($container);
	$tester->loginAs((new Fixtures($container))->user($scenario->municipality, role: Role::Reader));

	Assert::notContains('Doplnit výchozí šablony', $tester->render('Admin:Template'));
	$e = Assert::exception(fn() => $tester->run('Admin:Template', 'add'), BadRequestException::class);
	Assert::same(403, $e?->getHttpCode());
});

test('program of a meeting can be saved as a template from the templates page', function (): void {
	$container = ContainerFactory::create();
	TestDatabase::migrate($container);
	$scenario = MeetingScenario::create($container);
	$tester = new PresenterTester($container);
	$tester->loginAs((new Fixtures($container))->user($scenario->municipality));

	Assert::contains('Vytvořit šablonu z programu zasedání', $tester->render('Admin:Template'));
	Assert::notContains('Opakujete podobný program', $tester->render('Admin:Meeting', 'detail', [
		'id' => $scenario->meeting->getId(),
	]));

	PresenterTester::assertRedirect($tester->run('Admin:Template', 'default', [
		'do' => 'saveProgram',
	], post: [
		'meetingId' => (string) $scenario->meeting->getId(),
		'name' => 'Rozpočtové zasedání',
	]));
	$templates = $container->getByType(TemplateFacade::class)->findMeetingTemplates($scenario->municipality->getId());
	Assert::same(['Rozpočtové zasedání'], array_map(static fn($t) => $t->getName(), $templates));
});
