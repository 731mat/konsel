<?php

declare(strict_types=1);

namespace App\Model\AiAccess;

/**
 * Nově vydaný token – samotný řetězec existuje jen tady (v DB je hash) a ukáže se jednou.
 */
final readonly class IssuedAccessToken
{
	public function __construct(
		public AiAccessToken $token,
		#[\SensitiveParameter]
		public string $accessToken,
		#[\SensitiveParameter]
		public ?string $refreshToken = null,
	) {}
}
