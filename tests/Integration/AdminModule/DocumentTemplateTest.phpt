<?php

declare(strict_types=1);

use App\Model\Document\DocumentTemplateFacade;
use App\Model\Document\DocumentTemplateType;
use App\Model\Document\MeetingDocumentFacade;
use App\Model\Document\MeetingDocumentType;
use App\Model\Meeting\MeetingFacade;
use App\Model\People\BodyType;
use App\Model\People\Command\SaveBody;
use App\Model\People\PeopleFacade;
use App\Model\Shared\Exception\ValidationException;
use App\Model\User\Role;
use Nette\Application\Responses\CallbackResponse;
use Tester\Assert;
use Tests\Support\ContainerFactory;
use Tests\Support\Fixtures;
use Tests\Support\PresenterTester;
use Tests\Support\ResolutionScenario;
use Tests\Support\TestDatabase;

require __DIR__ . '/../../bootstrap.php';

/**
 * @return array{DocumentTemplateFacade, MeetingDocumentFacade, ResolutionScenario, Nette\DI\Container}
 */
function bootTemplates(): array
{
	$container = ContainerFactory::create();
	TestDatabase::migrate($container);
	$scenario = ResolutionScenario::create($container, ['Schvaluje: rozpočet na rok 2027.']);

	return [$container->getByType(DocumentTemplateFacade::class), $container->getByType(MeetingDocumentFacade::class), $scenario, $container];
}

test('all default templates render in the sandbox', function (): void {
	[$templates, $documents, $scenario, $container] = bootTemplates();
	$meeting = $container->getByType(MeetingFacade::class)->get($scenario->meeting->meeting->getId());

	foreach (MeetingDocumentType::cases() as $type) {
		Assert::contains('Neplachovice', $documents->renderHtml($meeting, $type, namedVotes: true), $type->value);
	}

	Assert::contains('1/2026', $documents->generateResolutionExtract($scenario->resolutions[0]->getId(), editable: true)->content);
	foreach (DocumentTemplateType::cases() as $type) {
		Assert::noError(fn() => $templates->save($scenario->meeting->municipality->getId(), $type, null, $templates->getDefaultSource($type), null));
	}
});

test('custom template of the municipality and of a body replaces the default', function (): void {
	[$templates, $documents, $scenario, $container] = bootTemplates();
	$municipalityId = $scenario->meeting->municipality->getId();
	$meetingId = $scenario->meeting->meeting->getId();
	$meetings = $container->getByType(MeetingFacade::class);

	$source = str_replace('<h1>Usnesení</h1>', '<h1>USNESENÍ ZASTUPITELSTVA</h1>', $templates->getDefaultSource(DocumentTemplateType::Resolutions));
	$templates->save($municipalityId, DocumentTemplateType::Resolutions, null, $source, null);
	Assert::contains('USNESENÍ ZASTUPITELSTVA', $documents->renderHtml($meetings->get($meetingId), MeetingDocumentType::Resolutions));
	Assert::contains('USNESENÍ ZASTUPITELSTVA', $templates->getSource($municipalityId, DocumentTemplateType::Resolutions, null));

	// šablona jiného orgánu zastupitelstvo neovlivní, šablona jeho orgánu má přednost před šablonou obce
	$term = $scenario->meeting->meeting->getBody()->getTerm();
	$board = $container->getByType(PeopleFacade::class)->createBody($term->getId(), new SaveBody(BodyType::Committee, 'Finanční výbor', 3));
	$templates->save($municipalityId, DocumentTemplateType::Layout, $board->getId(), str_replace('<body>', '<body><p>HLAVIČKA VÝBORU</p>', $templates->getDefaultSource(DocumentTemplateType::Layout)), null);
	Assert::notContains('HLAVIČKA VÝBORU', $documents->renderHtml($meetings->get($meetingId), MeetingDocumentType::Invitation));
	$bodyId = $scenario->meeting->meeting->getBody()->getId();
	$templates->save($municipalityId, DocumentTemplateType::Resolutions, $bodyId, str_replace('<h1>Usnesení</h1>', '<h1>ORGÁN</h1>', $templates->getDefaultSource(DocumentTemplateType::Resolutions)), null);
	Assert::contains('<h1>ORGÁN</h1>', $documents->renderHtml($meetings->get($meetingId), MeetingDocumentType::Resolutions));

	$templates->reset($municipalityId, DocumentTemplateType::Resolutions, $bodyId);
	$templates->reset($municipalityId, DocumentTemplateType::Resolutions, null);
	Assert::contains('<h1>Usnesení</h1>', $documents->renderHtml($meetings->get($meetingId), MeetingDocumentType::Resolutions));
});

test('unsafe or broken templates are refused with a readable message', function (): void {
	[$templates, , $scenario] = bootTemplates();
	$municipalityId = $scenario->meeting->municipality->getId();
	$minutes = $templates->getDefaultSource(DocumentTemplateType::Minutes);
	$save = fn(string $source) => $templates->save($municipalityId, DocumentTemplateType::Minutes, null, $source, null);

	foreach ([
		'{php echo 1}' => 'Tag {php} není v šablonách dokumentů povolené',
		'{=system("id")}' => 'Function system() není v šablonách dokumentů povolené',
		'{$meeting->getPlace()|noescape}' => 'Filter |noescape není v šablonách dokumentů povolené',
		'{=array_map("system", ["id"])}' => 'Function array_map() není v šablonách dokumentů povolené',
		'{$meeting->getMunicipality()->changeLogo(null)}' => 'changeLogo() není v šablonách dokumentů povolené',
		"{include '/etc/passwd'}" => 'neexistuje',
	] as $snippet => $message) {
		$e = Assert::exception(fn() => $save(str_replace('{block content}', "{block content}\n{$snippet}", $minutes)), ValidationException::class);
		Assert::contains($message, $e?->getMessage() ?? '', $snippet);
	}

	$e = Assert::exception(fn() => $save('{layout \'@layout.latte\'}{block content}<p>{$meeting->getTitle()}</p>{/block}'), ValidationException::class);
	Assert::contains('Šabloně chybí: počet přítomných ($attendance)', $e?->getMessage() ?? '');
	$e = Assert::exception(fn() => $save(str_replace('{block content}', "{block content}\n{if \$draft}", $minutes)), ValidationException::class);
	Assert::match('Chyba v šabloně na řádku %d%: %A%', $e?->getMessage() ?? '');
	Assert::same([], $templates->findCustom($municipalityId), 'nic se neuložilo');
});

test('admin edits a template in settings, previews it as PDF and saves it', function (): void {
	[$templates, , $scenario, $container] = bootTemplates();
	$tester = new PresenterTester($container);
	$tester->loginAs((new Fixtures($container))->user($scenario->meeting->municipality, 'spravce@neplachovice.cz', Role::Admin));
	$params = [
		'type' => 'resolutions',
	];

	Assert::contains('výchozí', $tester->render('Admin:Settings', 'documents'));
	Assert::contains('&lt;h1&gt;Usnesení&lt;/h1&gt;', $tester->render('Admin:Settings', 'document', $params), 'editor předvyplní výchozí šablonu');

	$source = str_replace('<h1>Usnesení</h1>', '<h1>Usnesení obce</h1>', $templates->getDefaultSource(DocumentTemplateType::Resolutions));
	$preview = $tester->submitForm('Admin:Settings', 'documentTemplateForm', [
		'source' => $source,
		'preview' => 'Náhled PDF',
	], 'document', params: $params);
	Assert::type(CallbackResponse::class, $preview);
	Assert::same([], $templates->findCustom($scenario->meeting->municipality->getId()), 'náhled neukládá');

	PresenterTester::assertRedirect($tester->submitForm('Admin:Settings', 'documentTemplateForm', [
		'source' => $source,
		'save' => 'Uložit šablonu',
	], 'document', params: $params));
	Assert::contains('vlastní – celá obec', $tester->render('Admin:Settings', 'documents'));
	Assert::contains('Obnovit výchozí', $tester->render('Admin:Settings', 'document', $params));
});
