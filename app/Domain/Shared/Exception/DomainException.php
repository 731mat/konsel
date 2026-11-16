<?php

declare(strict_types=1);

namespace App\Domain\Shared\Exception;

/**
 * Předek všech doménových výjimek. Zpráva je určená pro uživatele (česky).
 */
abstract class DomainException extends \DomainException {}
