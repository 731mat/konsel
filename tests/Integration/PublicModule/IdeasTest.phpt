<?php

declare(strict_types=1);

use App\Model\Comment\CommentAuthorRef;
use App\Model\Comment\CommentFacade;
use App\Model\Project\Command\SaveProject;
use App\Model\Project\ProjectFacade;
use App\Model\Project\ProjectStatus;
use App\Model\Storage\UploadedFile;
use Nette\Application\BadRequestException;
use Nette\Application\Responses\FileResponse;
use Tester\Assert;
use Tests\Support\ContainerFactory;
use Tests\Support\Fixtures;
use Tests\Support\PresenterTester;
use Tests\Support\TestDatabase;

require __DIR__ . '/../../bootstrap.php';

function photo(string $name): UploadedFile
{
	$stream = fopen('php://memory', 'w+b');
	assert($stream !== false);
	fwrite($stream, "\xFF\xD8\xFFjpeg");
	rewind($stream);

	return new UploadedFile($stream, $name);
}

test('citizen sees public projects with public comments only and submits a proposal', function (): void {
	$container = ContainerFactory::create();
	TestDatabase::migrate($container);
	$fixtures = new Fixtures($container);
	$municipality = $fixtures->municipality();
	$user = $fixtures->user($municipality);
	$projects = $container->getByType(ProjectFacade::class);
	$comments = $container->getByType(CommentFacade::class);

	$public = $projects->create($municipality->getId(), new SaveProject('Oprava chodníku', 'Hlavní ulice.'), 'Úřad');
	$projects->changeStatus($public->getId(), ProjectStatus::InProgress, 'Úřad', 'Firma vybrána');
	$projects->setPublic($public->getId(), true);
	$visible = $comments->addToProject($public->getId(), CommentAuthorRef::user($user->getId()), 'Práce začnou v pondělí.', files: [photo('plan.jpg')], publicForCitizens: true);
	$internal = $comments->addToProject($public->getId(), CommentAuthorRef::user($user->getId()), 'Interní: firma chce zálohu.', files: [photo('faktura.jpg')]);
	$projects->create($municipality->getId(), new SaveProject('Tajný záměr'), 'Úřad');
	$tester = new PresenterTester($container);
	$params = [
		'municipality' => 'neplachovice',
	];

	$list = $tester->render('Public:Ideas', 'default', $params);
	Assert::contains('Oprava chodníku', $list);
	Assert::notContains('Tajný záměr', $list);

	$detail = $tester->render('Public:Ideas', 'detail', $params + [
		'id' => $public->getId(),
	]);
	Assert::contains('Práce začnou v pondělí.', $detail);
	Assert::contains('Firma vybrána', $detail);
	Assert::notContains('Interní: firma chce zálohu.', $detail);
	Assert::notContains('Jana Nováková', $detail, 'jméno úředníka se veřejně nezobrazuje');

	Assert::type(FileResponse::class, $tester->run('Public:Ideas', 'file', $params + [
		'id' => $visible->getAttachments()[0]->getId(),
	]));
	$e = Assert::exception(fn() => $tester->run('Public:Ideas', 'file', $params + [
		'id' => $internal->getAttachments()[0]->getId(),
	]), BadRequestException::class);
	Assert::same(404, $e?->getHttpCode());

	PresenterTester::assertRedirect($tester->submitForm('Public:Ideas', 'proposalForm', [
		'name' => 'Marie Občanová',
		'email' => 'marie@example.cz',
		'title' => 'Pítko pro psy na návsi',
		'description' => 'U kostela chybí lavičky.',
		'website' => '',
		'consent' => 'on',
	], params: $params));
	$submitted = $projects->find($municipality->getId(), ProjectStatus::Submitted);
	Assert::count(1, $submitted);
	$log = $container->getByType(Doctrine\DBAL\Connection::class)->fetchAllAssociative('SELECT type, status FROM mail_log');
	Assert::same([], $log, 'obec bez e-mailu – upozornění se neposílá, podnět se přesto přijme');
	Assert::false($submitted[0]->isPublic());
	Assert::same('marie@example.cz', $submitted[0]->getSubmitterEmail()?->value);
	Assert::notContains('Pítko pro psy na návsi', $tester->render('Public:Ideas', 'default', $params), 'podnět je neveřejný do posouzení');
});

test('honeypot, consent and rate limit protect the form; unknown municipality is 404', function (): void {
	$container = ContainerFactory::create();
	TestDatabase::migrate($container);
	$municipality = (new Fixtures($container))->municipality();
	$projects = $container->getByType(ProjectFacade::class);
	$tester = new PresenterTester($container);
	$params = [
		'municipality' => 'neplachovice',
	];
	$form = static fn(string $title, string $website = '', string $consent = 'on'): array => [
		'name' => 'Robot',
		'email' => 'robot@example.cz',
		'title' => $title,
		'description' => '',
		'website' => $website,
		'consent' => $consent,
	];

	PresenterTester::assertRedirect($tester->submitForm('Public:Ideas', 'proposalForm', $form('Spam', 'http://spam.example'), params: $params));
	Assert::same([], $projects->find($municipality->getId()), 'vyplněná past na roboty → nic se neuloží');

	Assert::contains('Bez souhlasu nemůžeme podnět přijmout.', PresenterTester::renderResponse($tester->submitForm('Public:Ideas', 'proposalForm', $form('Bez souhlasu', consent: ''), params: $params)));

	foreach ([1, 2, 3] as $i) {
		PresenterTester::assertRedirect($tester->submitForm('Public:Ideas', 'proposalForm', $form("Podnět {$i}"), params: $params));
	}

	Assert::contains('příliš mnoho podnětů', PresenterTester::renderResponse($tester->submitForm('Public:Ideas', 'proposalForm', $form('Čtvrtý'), params: $params)));
	Assert::count(3, $projects->find($municipality->getId()));

	$e = Assert::exception(fn() => $tester->run('Public:Ideas', 'default', [
		'municipality' => 'neexistuje',
	]), BadRequestException::class);
	Assert::same(404, $e?->getHttpCode());
});
