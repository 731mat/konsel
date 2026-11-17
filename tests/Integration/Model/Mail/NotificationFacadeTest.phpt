<?php

declare(strict_types=1);

use App\Model\Mail\NotificationFacade;
use App\Model\Municipality\Command\UpdateMunicipalitySettings;
use App\Model\Municipality\MunicipalityFacade;
use App\Model\Project\ProjectFacade;
use Tester\Assert;
use Tests\Support\ContainerFactory;
use Tests\Support\FakeMailer;
use Tests\Support\Fixtures;
use Tests\Support\TestDatabase;

require __DIR__ . '/../../../bootstrap.php';

test('office is notified about a new citizen proposal; reply goes to the citizen', function (): void {
	$container = ContainerFactory::create();
	TestDatabase::migrate($container);
	$municipality = (new Fixtures($container))->municipality();
	$project = $container->getByType(ProjectFacade::class)->submitProposal($municipality->getId(), 'Marie Občanová', 'marie@seznam.cz', 'Lavičky v parku', 'U kostela chybí lavičky.', null);
	$facade = $container->getByType(NotificationFacade::class);
	$mailer = $container->getByType(App\Model\Mail\Mailer::class);
	assert($mailer instanceof FakeMailer);

	Assert::false($facade->notifyNewProposal($project->getId(), 'https://konsel.test/admin/project/detail/1'), 'obec bez e-mailu – není komu psát');
	Assert::same([], $mailer->sent);

	$container->getByType(MunicipalityFacade::class)->updateSettings($municipality->getId(), new UpdateMunicipalitySettings(name: 'Neplachovice', officialName: 'Obec Neplachovice', email: 'podatelna@neplachovice.cz'));
	Assert::true($facade->notifyNewProposal($project->getId(), 'https://konsel.test/admin/project/detail/1'));
	$message = $mailer->sent[0];
	Assert::same('podatelna@neplachovice.cz', $message->to->value);
	Assert::same('Nový podnět občana: Lavičky v parku', $message->subject);
	Assert::same('marie@seznam.cz', $message->replyTo?->value);
	Assert::contains('U kostela chybí lavičky.', $message->html);
	Assert::contains('https://konsel.test/admin/project/detail/1', $message->html);

	$mailer->failFor = ['podatelna@neplachovice.cz'];
	Assert::false($facade->notifyNewProposal($project->getId(), 'https://konsel.test/admin/project/detail/1'), 'selhání se jen zaznamená');
});
