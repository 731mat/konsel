<?php

declare(strict_types=1);

use App\Model\Meeting\AgendaLockFacade;
use App\Model\Meeting\LockedException;
use App\Model\Meeting\MeetingFacade;
use App\Model\Meeting\MeetingTransition;
use App\Model\Meeting\ProceedingsFacade;
use App\Model\Shared\Exception\ConcurrencyException;
use App\Model\User\Role;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\MockClock;
use Tester\Assert;
use Tests\Support\ContainerFactory;
use Tests\Support\Fixtures;
use Tests\Support\MeetingScenario;
use Tests\Support\PresenterTester;
use Tests\Support\TestDatabase;

require __DIR__ . '/../../../bootstrap.php';

test('second clerk sees the lock, takes it over after it expires; locked discussion cannot be overwritten', function (): void {
	$container = ContainerFactory::create();
	TestDatabase::migrate($container);
	$scenario = MeetingScenario::create($container);
	$fixtures = new Fixtures($container);
	$mayor = $fixtures->user($scenario->municipality, 'starosta@obec.cz', Role::Admin, 'Jan Starosta');
	$clerk = $fixtures->user($scenario->municipality, 'zapisovatel@obec.cz', Role::Editor, 'Eva Zapisovatelka');
	$meetings = $container->getByType(MeetingFacade::class);
	$meetings->transition($scenario->meeting->getId(), MeetingTransition::Share);
	$meetings->transition($scenario->meeting->getId(), MeetingTransition::Start);
	$locks = $container->getByType(AgendaLockFacade::class);
	$proceedings = $container->getByType(ProceedingsFacade::class);
	$clock = $container->getByType(ClockInterface::class);
	assert($clock instanceof MockClock);
	$itemId = $scenario->item->getId();

	$locks->acquire($itemId, $mayor->getId());
	$e = Assert::exception(fn() => $locks->acquire($itemId, $clerk->getId()), LockedException::class, 'Bod právě upravuje Jan Starosta.%A%');
	Assert::same('Jan Starosta', $e?->holderName);
	Assert::count(1, $locks->findForeignLocks($scenario->meeting->getId(), $clerk->getId()));
	Assert::count(0, $locks->findForeignLocks($scenario->meeting->getId(), $mayor->getId()), 'vlastní zámek není cizí');
	Assert::exception(fn() => $proceedings->recordDiscussion($itemId, 'Přepisuji starostu', $clerk->getId()), LockedException::class);
	$proceedings->recordDiscussion($itemId, 'Starosta zapsal diskusi.', $mayor->getId());

	$clock->sleep(30);
	$locks->acquire($itemId, $mayor->getId());
	$clock->sleep(45);
	Assert::exception(fn() => $locks->acquire($itemId, $clerk->getId()), LockedException::class); // heartbeat prodloužil zámek

	$clock->sleep(61);
	$locks->acquire($itemId, $clerk->getId());
	Assert::exception(fn() => $locks->acquire($itemId, $mayor->getId()), LockedException::class, 'Bod právě upravuje Eva Zapisovatelka.%A%');
	$locks->release($itemId, $mayor->getId());
	Assert::count(1, $locks->findForeignLocks($scenario->meeting->getId(), $mayor->getId()), 'cizí zámek nejde uvolnit');
	$locks->release($itemId, $clerk->getId());
	Assert::count(0, $locks->findForeignLocks($scenario->meeting->getId(), $mayor->getId()));

	$locks->acquire($itemId, $clerk->getId());
	$clock->sleep(120);
	Assert::same(1, $locks->cleanup());

	$tester = new PresenterTester($container);
	$tester->loginAs($clerk);
	$locks->acquire($itemId, $mayor->getId());
	$response = $tester->run('Admin:Proceedings', 'default', [
		'id' => $scenario->meeting->getId(),
		'do' => 'lock',
		'itemId' => $itemId,
	], post: [
		'x' => '1',
	]);
	Assert::type(Nette\Application\Responses\JsonResponse::class, $response);
	assert($response instanceof Nette\Application\Responses\JsonResponse);
	$payload = $response->getPayload();
	Assert::same('Jan Starosta', $payload instanceof stdClass ? $payload->lockedBy : null);
	$html = $tester->render('Admin:Proceedings', 'default', [
		'id' => $scenario->meeting->getId(),
	]);
	Assert::contains('Upravuje Jan Starosta', $html);
	Assert::match('%A%id="discussion-' . $itemId . '"%A%readonly%A%', $html);
});

test('stale meeting in memory cannot overwrite a newer version (optimistic lock)', function (): void {
	$container = ContainerFactory::create();
	TestDatabase::migrate($container);
	$scenario = MeetingScenario::create($container);
	$meetings = $container->getByType(MeetingFacade::class);
	$meetings->get($scenario->meeting->getId());
	$container->getByType(Connection::class)->executeStatement('UPDATE meeting SET version = version + 1 WHERE id = ?', [$scenario->meeting->getId()]);

	Assert::exception(fn() => $meetings->transition($scenario->meeting->getId(), MeetingTransition::Share), ConcurrencyException::class, 'Mezitím změnil stejné údaje někdo jiný.%A%');
});
