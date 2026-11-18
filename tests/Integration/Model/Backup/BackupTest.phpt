<?php

declare(strict_types=1);

use App\Model\Backup\BackupContent;
use App\Model\Backup\BackupFacade;
use App\Model\Backup\BackupFrequency;
use App\Model\Backup\BackupTrigger;
use App\Model\Backup\Command\SaveBackupSettings;
use App\Model\Shared\Exception\NotFoundException;
use App\Model\Storage\FileStorage;
use App\Model\User\Role;
use Nette\Application\BadRequestException;
use Nette\Application\Responses\FileResponse;
use Symfony\Component\Clock\MockClock;
use Tester\Assert;
use Tests\Support\ContainerFactory;
use Tests\Support\Fixtures;
use Tests\Support\PresenterTester;
use Tests\Support\TestDatabase;

require __DIR__ . '/../../../bootstrap.php';

test('full backup contains database, uploaded files and manifest; old ones are pruned', function (): void {
	$container = ContainerFactory::create();
	TestDatabase::migrate($container);
	(new Fixtures($container))->municipality();
	$stored = $container->getByType(FileStorage::class)->storeLocalFile(__FILE__, 'zapis.txt', 'm1/attachments');
	$backups = $container->getByType(BackupFacade::class);
	foreach ($backups->list() as $old) {
		$backups->delete($old->name);
	}

	$full = $backups->run(BackupContent::Full, BackupTrigger::Manual);
	Assert::match('konsel-%d%-%d%-full-manual.zip', $full->name);
	$zip = new ZipArchive();
	Assert::true($zip->open($full->path));
	Assert::notSame(false, $zip->locateName('database.sqlite'));
	Assert::notSame(false, $zip->locateName("storage/{$stored->path}"), 'nahraný soubor je v záloze');
	$manifest = json_decode((string) $zip->getFromName('manifest.json'), true);
	Assert::same('full', is_array($manifest) ? $manifest['content'] : null);
	$zip->close();

	$dbOnly = $backups->run(BackupContent::Database, BackupTrigger::Manual);
	$zip->open($dbOnly->path);
	Assert::false($zip->locateName("storage/{$stored->path}"), 'jen databáze');
	$zip->close();
	Assert::true($backups->getSettings()->getLastSucceeded());

	// držet jen 1 – starší se smažou po další záloze
	$backups->saveSettings(new SaveBackupSettings(false, BackupFrequency::Daily, 3, 1, 1, BackupContent::Database, 1));
	$clock = $container->getByType(Psr\Clock\ClockInterface::class);
	assert($clock instanceof MockClock);
	$clock->sleep(2);
	$backups->run(BackupContent::Database, BackupTrigger::Manual);
	Assert::count(1, $backups->list());

	Assert::exception(fn() => $backups->get('../database.sqlite'), NotFoundException::class);
	Assert::exception(fn() => $backups->get('konsel-20260101-000000-full-manual.zip'), NotFoundException::class);
});

test('scheduled backup runs only when due', function (): void {
	$container = ContainerFactory::create();
	TestDatabase::migrate($container);
	$backups = $container->getByType(BackupFacade::class);
	Assert::null($backups->runScheduled(), 'plán je výchozí vypnutý');

	$backups->saveSettings(new SaveBackupSettings(true, BackupFrequency::Daily, 3, 1, 1, BackupContent::Database, 5));
	$file = $backups->runScheduled();
	Assert::notNull($file, 'v 10:00 je ranní záloha na řadě');
	Assert::same(BackupTrigger::Scheduled, $file?->trigger);
	Assert::null($backups->runScheduled(), 'dnes už proběhla');
});

test('backups page: admin of the only municipality, not editors or admins of a shared installation', function (): void {
	$container = ContainerFactory::create();
	TestDatabase::migrate($container);
	$fixtures = new Fixtures($container);
	$municipality = $fixtures->municipality();
	$tester = new PresenterTester($container);
	$tester->loginAs($fixtures->user($municipality, 'spravce@neplachovice.cz', Role::Admin));

	Assert::contains('/admin/backup', $tester->render('Admin:Settings'), 'záložka Zálohy');
	$html = $tester->render('Admin:Backup');
	Assert::contains('Pravidelné zálohy', $html);
	Assert::contains('0 * * * * php /app/bin/console backup:run --scheduled', $html);

	PresenterTester::assertRedirect($tester->run('Admin:Backup', 'default', [
		'do' => 'run',
		'content' => 'db',
	], post: [
		'confirm' => '1',
	]));
	$latest = $container->getByType(BackupFacade::class)->list()[0];
	Assert::type(FileResponse::class, $tester->run('Admin:Backup', 'download', [
		'name' => $latest->name,
	]));

	$tester->loginAs($fixtures->user($municipality, 'editor@neplachovice.cz', Role::Editor));
	$e = Assert::exception(fn() => $tester->run('Admin:Backup'), BadRequestException::class);
	Assert::same(403, $e?->getHttpCode());

	$fixtures->municipality('Jiná obec');
	$tester->loginAs($fixtures->user($municipality, 'spravce2@neplachovice.cz', Role::Admin));
	$e = Assert::exception(fn() => $tester->run('Admin:Backup'), BadRequestException::class);
	Assert::same(403, $e?->getHttpCode(), 've sdílené instalaci by záloha obsahovala data cizích obcí');
	Assert::notContains('/admin/backup', $tester->render('Admin:Settings'));
});
