<?php

declare(strict_types=1);

namespace App\Model\Mail;

/**
 * Selhání odeslání (SMTP nedostupné, odmítnutý příjemce…). Zapisuje se do MailLog, uživateli se ukáže souhrn.
 */
final class MailException extends \RuntimeException {}
