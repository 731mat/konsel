<?php

declare(strict_types=1);

namespace App\Model\AiAccess;

use App\Model\Shared\Exception\DomainException;

/**
 * Chyba protokolu OAuth 2.1 s kódem podle RFC 6749 (invalid_grant, invalid_client…).
 */
final class OAuthException extends DomainException
{
	public function __construct(
		public readonly string $error,
		string $description,
	) {
		parent::__construct($description);
	}
}
