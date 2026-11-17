<?php

declare(strict_types=1);

use App\Model\Access\AccessFacade;
use App\Model\Municipality\Module;
use App\Model\Municipality\MunicipalityFacade;
use App\Model\Project\Command\SaveProject;
use App\Model\Project\ProjectFacade;
use App\Model\Resolution\Command\SaveResolutionTask;
use App\Model\Resolution\ResolutionTaskFacade;
use App\Model\User\Role;
use Nette\Application\BadRequestException;
use Tester\Assert;
use Tests\Support\ContainerFactory;
use Tests\Support\Fixtures;
use Tests\Support\PresenterTester;
use Tests\Support\ResolutionScenario;
use Tests\Support\TestDatabase;

require __DIR__ . '/../../bootstrap.php';

/**
 * @param array<string, mixed> $params
 */
function assertNotFound(PresenterTester $tester, string $presenter, string $action = 'default', array $params = []): void
{
	$e = Assert::exception(fn() => $tester->run($presenter, $action, $params), BadRequestException::class, null);
	Assert::same(404, $e?->getHttpCode(), "{$presenter}:{$action}");
}

test('admin switches modules off in settings; resolution tasks and plans disappear everywhere', function (): void {
	$container = ContainerFactory::create();
	TestDatabase::migrate($container);
	$scenario = ResolutionScenario::create($container);
	$municipality = $scenario->meeting->municipality;
	$resolution = $scenario->resolutions[0];
	$container->getByType(ResolutionTaskFacade::class)->add($resolution->getId(), new SaveResolutionTask('Podepsat smlouvu', null, null));
	$projects = $container->getByType(ProjectFacade::class);
	$plan = $projects->create($municipality->getId(), new SaveProject('Nová mateřská škola'), 'Úřad');
	$projects->setPublic($plan->getId(), true);
	$projects->linkAgendaItem($scenario->meeting->item->getId(), $plan->getId());
	[$issued] = $container->getByType(AccessFacade::class)->issueForMembers($scenario->meeting->meeting->getId());

	$tester = new PresenterTester($container);
	$tester->loginAs((new Fixtures($container))->user($municipality, 'spravce@neplachovice.cz', Role::Admin));

	// výchozí stav: vše zapnuto
	$dashboard = $tester->render('Admin:Dashboard');
	Assert::contains('Plány', $dashboard);
	Assert::contains('Úkoly z usnesení', $dashboard);
	Assert::contains('Podepsat smlouvu', $tester->render('Admin:Resolution', 'detail', [
		'id' => $resolution->getId(),
	]));
	Assert::contains('name="plans" id="frm-modulesForm-plans" checked', $tester->render('Admin:Settings', 'modules'));

	PresenterTester::assertRedirect($tester->submitForm('Admin:Settings', 'modulesForm', [], 'modules'));
	$fresh = $container->getByType(MunicipalityFacade::class)->get($municipality->getId());
	Assert::false($fresh->isModuleEnabled(Module::Plans));
	Assert::false($fresh->isModuleEnabled(Module::ResolutionTasks));

	$dashboard = $tester->render('Admin:Dashboard');
	Assert::notContains('/admin/project', $dashboard, 'menu bez plánů');
	Assert::notContains('/admin/task', $dashboard, 'menu i přehled bez úkolů z usnesení');
	assertNotFound($tester, 'Admin:Task');
	assertNotFound($tester, 'Admin:Project');
	assertNotFound($tester, 'Admin:Project', 'detail', [
		'id' => $plan->getId(),
	]);

	$detail = $tester->render('Admin:Resolution', 'detail', [
		'id' => $resolution->getId(),
	]);
	Assert::notContains('Podepsat smlouvu', $detail);
	Assert::notContains('Přidat úkol', $detail);

	$item = $tester->render('Admin:Agenda', 'detail', [
		'id' => $scenario->meeting->item->getId(),
	]);
	Assert::notContains('Nová mateřská škola', $item, 'propojení s plánem se neukazuje');
	Assert::notContains('Nová mateřská škola', $tester->render('Admin:Search', 'default', [
		'q' => 'materska skola',
	]));
	Assert::notContains('value="project"', $tester->render('Admin:Search', 'default', [
		'q' => 'skola',
	]), 'druh Plán není ve filtru');

	// stránka pro občany, rozcestník a portál zastupitele
	assertNotFound($tester, 'Public:Ideas', 'default', [
		'municipality' => $municipality->getSlug(),
	]);
	Assert::notContains('Jsem občan', $tester->render('Public:Homepage'));
	PresenterTester::assertRedirect($tester->run('Portal:Entry', 'default', [
		'token' => $issued->token,
	]));
	Assert::notContains('/portal/project', $tester->render('Portal:Meeting'));
	assertNotFound($tester, 'Portal:Project');

	// data zůstala – po zapnutí je vše zpět
	PresenterTester::assertRedirect($tester->submitForm('Admin:Settings', 'modulesForm', [
		'plans' => 'on',
		'resolution_tasks' => 'on',
	], 'modules'));
	Assert::contains('Nová mateřská škola', $tester->render('Admin:Project'));
	Assert::contains('Podepsat smlouvu', $tester->render('Admin:Task'));
	Assert::contains('Jsem občan', $tester->render('Public:Homepage'));
});

test('editor cannot change modules', function (): void {
	$container = ContainerFactory::create();
	TestDatabase::migrate($container);
	$fixtures = new Fixtures($container);
	$tester = new PresenterTester($container);
	$tester->loginAs($fixtures->user($fixtures->municipality()));

	$e = Assert::exception(fn() => $tester->run('Admin:Settings', 'modules'), BadRequestException::class);
	Assert::same(403, $e?->getHttpCode());
});
