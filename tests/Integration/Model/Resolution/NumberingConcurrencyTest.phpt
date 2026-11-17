<?php

declare(strict_types=1);

use Tester\Assert;

require __DIR__ . '/../../../bootstrap.php';

test('three processes allocating numbers at the same time never get the same number', function (): void {
	$path = sys_get_temp_dir() . '/konsel-numbering-' . getmypid() . '.sqlite';
	@unlink($path);
	$pdo = new PDO("sqlite:{$path}");
	$pdo->exec('PRAGMA journal_mode = WAL');
	$pdo->exec('CREATE TABLE numbering_sequence (id INTEGER PRIMARY KEY AUTOINCREMENT, municipality_id INTEGER NOT NULL, body_id INTEGER NOT NULL, scope_key VARCHAR(50) NOT NULL, last_value INTEGER NOT NULL, UNIQUE (body_id, scope_key))');
	unset($pdo);

	$perProcess = 40;
	$processes = [];
	foreach (range(1, 3) as $i) {
		$process = proc_open([PHP_BINARY, __DIR__ . '/../../../fixtures/numbering-worker.php', $path, (string) $perProcess], [
			1 => ['pipe', 'w'],
			2 => ['pipe', 'w'],
		], $pipes);
		if ($process === false) {
			throw new RuntimeException('Proces se nepodařilo spustit.');
		}

		$processes[] = [$process, $pipes];
	}

	$numbers = [];
	foreach ($processes as [$process, $pipes]) {
		$output = (string) stream_get_contents($pipes[1]);
		$errors = (string) stream_get_contents($pipes[2]);
		Assert::same(0, proc_close($process), "proces skončil chybou: {$errors}");
		array_push($numbers, ...array_map('intval', explode(',', $output)));
	}

	@unlink($path);
	sort($numbers);
	Assert::same(range(1, 3 * $perProcess), $numbers, 'každé číslo právě jednou, bez mezer');
});
