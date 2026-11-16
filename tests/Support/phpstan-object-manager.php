<?php

declare(strict_types=1);

use Doctrine\ORM\EntityManagerInterface;

require __DIR__ . '/../../vendor/autoload.php';

return (new App\Bootstrap())->bootTestContainer()->getByType(EntityManagerInterface::class);
