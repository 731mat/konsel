<?php

declare(strict_types=1);

use App\Model\Backup\BackupContent;
use App\Model\Backup\BackupFrequency;
use App\Model\Backup\BackupSettings;
use App\Model\Shared\Exception\ValidationException;
use Tester\Assert;

require __DIR__ . '/../../../bootstrap.php';

function at(string $time): DateTimeImmutable
{
	return new DateTimeImmutable($time, new DateTimeZone('Europe/Prague'));
}

test('daily backup at 3:00 is due once per day', function (): void {
	$settings = new BackupSettings();
	Assert::false($settings->isDue(at('2026-11-18 10:00')), 'vypnutý plán nezálohuje');
	$settings->configure(true, BackupFrequency::Daily, 3, 1, 1, BackupContent::Full, 7);

	Assert::same('2026-11-18 03:00', $settings->lastSlot(at('2026-11-18 10:00'))->format('Y-m-d H:i'));
	Assert::same('2026-11-17 03:00', $settings->lastSlot(at('2026-11-18 02:59'))->format('Y-m-d H:i'));
	Assert::same('2026-11-19 03:00', $settings->nextSlot(at('2026-11-18 10:00'))->format('Y-m-d H:i'));
	Assert::true($settings->isDue(at('2026-11-18 03:05')), 'ještě nikdy nezálohováno');

	$settings->recordRun(at('2026-11-18 03:05'), true, null);
	Assert::false($settings->isDue(at('2026-11-18 23:00')));
	Assert::true($settings->isDue(at('2026-11-19 03:00')));
	Assert::true($settings->isDue(at('2026-11-21 12:00')), 'zmeškaný termín (server neběžel) dožene při dalším spuštění');
});

test('weekly on Sunday and monthly on the 1st', function (): void {
	$weekly = new BackupSettings();
	$weekly->configure(true, BackupFrequency::Weekly, 22, 7, 1, BackupContent::Database, 4);
	Assert::same('2026-11-15 22:00', $weekly->lastSlot(at('2026-11-18 10:00'))->format('Y-m-d H:i'), 'neděle 15. 11.');
	Assert::same('2026-11-22 22:00', $weekly->nextSlot(at('2026-11-18 10:00'))->format('Y-m-d H:i'));

	$monthly = new BackupSettings();
	$monthly->configure(true, BackupFrequency::Monthly, 1, 1, 1, BackupContent::Full, 12);
	Assert::same('2026-11-01 01:00', $monthly->lastSlot(at('2026-11-18 10:00'))->format('Y-m-d H:i'));
	Assert::same('2026-10-01 01:00', $monthly->lastSlot(at('2026-11-01 00:30'))->format('Y-m-d H:i'));
	Assert::same('2026-12-01 01:00', $monthly->nextSlot(at('2026-11-18 10:00'))->format('Y-m-d H:i'));

	Assert::exception(fn() => $monthly->configure(true, BackupFrequency::Monthly, 1, 1, 31, BackupContent::Full, 12), ValidationException::class, 'Den v měsíci musí být 1 až 28 (aby existoval v každém měsíci).');
	Assert::exception(fn() => $monthly->configure(true, BackupFrequency::Daily, 24, 1, 1, BackupContent::Full, 12), ValidationException::class, 'Hodina musí být 0 až 23.');
});
