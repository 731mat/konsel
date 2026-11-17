<?php

declare(strict_types=1);

// Pomocný proces pro NumberingConcurrencyTest: přidělí N čísel v samostatných transakcích a vypíše je.

use App\Core\Doctrine\SqlitePragmaMiddleware;
use App\Model\Resolution\DoctrineNumberingSequenceRepository;
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;

require __DIR__ . '/../../vendor/autoload.php';

[, $path, $count] = $argv;
$connection = DriverManager::getConnection([
	'driver' => 'pdo_sqlite',
	'path' => $path,
], (new Configuration())->setMiddlewares([new SqlitePragmaMiddleware()]));

$numbers = [];
for ($i = 0; $i < (int) $count; $i++) {
	$numbers[] = $connection->transactional(static function (Connection $connection): int {
		$number = DoctrineNumberingSequenceRepository::allocate($connection, 1, 1, 'Y2026');
		usleep(random_int(0, 1500)); // delší transakce = víc příležitostí k souběhu

		return $number;
	});
}

echo implode(',', $numbers);
