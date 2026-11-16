<?php

declare(strict_types=1);

namespace App\Model\Ai;

/**
 * Selhání AI služby (neplatný klíč, limit, výpadek). Zpráva je česky a lze ji ukázat uživateli.
 */
final class AiException extends \RuntimeException {}
