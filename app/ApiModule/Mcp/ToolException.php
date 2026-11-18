<?php

declare(strict_types=1);

namespace App\ApiModule\Mcp;

/**
 * Chyba nástroje, kterou dostane AI jako výsledek s isError (ne jako chybu protokolu).
 */
final class ToolException extends \RuntimeException {}
