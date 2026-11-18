<?php

declare(strict_types=1);

use App\Model\Audit\AuditAction;
use App\Model\Audit\AuditActor;
use App\Model\Audit\AuditContext;
use App\Model\Audit\AuditEntityType;
use App\Model\Audit\AuditEntry;
use App\Model\Audit\AuditFacade;
use App\Model\Audit\AuditFilter;
use App\Model\Meeting\MeetingFacade;
use App\Model\Meeting\MeetingTransition;
use App\Model\Meeting\VoteChoice;
use App\Model\Meeting\VotingFacade;
use App\Model\Shared\Exception\DomainException;
use App\Model\User\Role;
use App\Model\User\UserFacade;
use Nette\Application\BadRequestException;
use Tester\Assert;
use Tests\Support\ContainerFactory;
use Tests\Support\Fixtures;
use Tests\Support\PresenterTester;
use Tests\Support\ResolutionScenario;
use Tests\Support\TestDatabase;

require __DIR__ . '/../../../bootstrap.php';

/**
 * @param list<AuditEntry> $entries
 * @return list<AuditEntry>
 */
function ofType(array $entries, AuditEntityType $type, AuditAction $action): array
{
	return array_values(array_filter($entries, static fn(AuditEntry $e): bool => $e->getEntityType() === $type && $e->getAction() === $action));
}

test('changed vote is traceable: who, when, from what to what', function (): void {
	$container = ContainerFactory::create();
	TestDatabase::migrate($container);
	$scenario = ResolutionScenario::create($container);
	$municipalityId = $scenario->meeting->municipality->getId();
	$admin = (new Fixtures($container))->user($scenario->meeting->municipality, 'spravce@neplachovice.cz', Role::Admin, 'Jana Správcová');
	$container->getByType(AuditContext::class)->setActor(new AuditActor('Jana Správcová', $admin->getId(), '10.0.0.7'));

	$voting = $container->getByType(VotingFacade::class);
	$resolution = $scenario->resolutions[0];
	$round = $resolution->getVotingRound();
	assert($round !== null);
	$voter = $scenario->members[2];
	$voting->reopen($round->getId());
	$voting->cast($round->getId(), $voter->getId(), VoteChoice::Against);
	$voting->close($round->getId());

	$audit = $container->getByType(AuditFacade::class);
	$history = $audit->findForResolution($resolution->getId());
	[$changed] = ofType($history, AuditEntityType::Vote, AuditAction::Updated);
	Assert::same('Hlas: Člen Novotný (hlasování č. 1, bod „Rozpočet na rok 2027“)', $changed->getEntityLabel());
	Assert::same([
		'choice' => ['pro', 'proti'],
	], $changed->getChanges(), 'z čeho na co');
	Assert::same('Jana Správcová', $changed->getActorName(), 'kdo');
	Assert::same($admin->getId(), $changed->getActorUser()?->getId());
	Assert::same('10.0.0.7', $changed->getIp());
	Assert::same('2026-11-16', $changed->getCreatedAt()->format('Y-m-d'), 'kdy');
	Assert::same($round->getId(), $changed->getRelatedId());
	Assert::count(1, ofType($history, AuditEntityType::Resolution, AuditAction::Created), 'vznik usnesení (systém při přípravě scénáře)');

	[$entries] = $audit->find($municipalityId, new AuditFilter(type: AuditEntityType::Vote, actorUserId: $admin->getId()));
	Assert::count(1, $entries, 'filtr: hlasy, které změnila Jana');
	Assert::same($scenario->meeting->meeting->getId(), $entries[0]->getMeetingId());
});

test('sensitive fields are never stored, rolled back changes are not recorded', function (): void {
	$container = ContainerFactory::create();
	TestDatabase::migrate($container);
	$scenario = ResolutionScenario::create($container);
	$municipalityId = $scenario->meeting->municipality->getId();
	$user = (new Fixtures($container))->user($scenario->meeting->municipality);
	$container->getByType(UserFacade::class)->resetPassword($user->getId());

	$audit = $container->getByType(AuditFacade::class);
	[$entries] = $audit->find($municipalityId, new AuditFilter(type: AuditEntityType::User));
	foreach ($entries as $entry) {
		Assert::false(array_key_exists('passwordHash', $entry->getChanges()), 'heslo (ani jeho otisk) se nezapisuje');
	}

	Assert::same('systém', $entries[0]->getActorName(), 'mimo požadavek (konzole, test) je autor systém');

	[, $before] = $audit->find($municipalityId, new AuditFilter(), 1);
	$meetings = $container->getByType(MeetingFacade::class);
	$meetingId = $scenario->meeting->meeting->getId();
	$meetings->transition($meetingId, MeetingTransition::Close);
	$meetings->transition($meetingId, MeetingTransition::ApproveMinutes);
	[, $afterApproval] = $audit->find($municipalityId, new AuditFilter(), 1);
	Assert::true($afterApproval > $before, 'ukončení a ověření se zapsaly');
	try {
		$meetings->transition($meetingId, MeetingTransition::Start);
	} catch (DomainException) {
		// očekávané
	}

	[, $afterFailure] = $audit->find($municipalityId, new AuditFilter(), 1);
	Assert::same($afterApproval, $afterFailure, 'neprovedená změna v historii není');
});

test('admin sees the overview, meeting and resolution history; reader does not', function (): void {
	$container = ContainerFactory::create();
	TestDatabase::migrate($container);
	$scenario = ResolutionScenario::create($container);
	$fixtures = new Fixtures($container);
	$tester = new PresenterTester($container);
	$tester->loginAs($fixtures->user($scenario->meeting->municipality, 'spravce@neplachovice.cz', Role::Admin));

	$html = $tester->render('Admin:Audit');
	Assert::contains('Historie změn', $html);
	Assert::contains('Usnesení č. 1/2026', $html);
	Assert::contains('/admin/meeting/detail/' . $scenario->meeting->meeting->getId(), $html);
	Assert::contains('Hlas: Člen Dvořák', $tester->render('Admin:Meeting', 'detail', [
		'id' => $scenario->meeting->meeting->getId(),
		'tab' => 'history',
	]));
	Assert::contains('Historie změn', $tester->render('Admin:Resolution', 'detail', [
		'id' => $scenario->resolutions[0]->getId(),
	]));

	$tester->loginAs($fixtures->user($scenario->meeting->municipality, 'ctenar@neplachovice.cz', Role::Reader));
	$e = Assert::exception(fn() => $tester->run('Admin:Audit'), BadRequestException::class);
	Assert::same(403, $e?->getHttpCode());
	Assert::notContains('tab=history', $tester->render('Admin:Meeting', 'detail', [
		'id' => $scenario->meeting->meeting->getId(),
	]));
});
