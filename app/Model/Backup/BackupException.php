<?php

declare(strict_types=1);

namespace App\Model\Backup;

/**
 * Zálohu se nepodařilo vytvořit (místo na disku, oprávnění, databáze).
 */
final class BackupException extends \RuntimeException {}
