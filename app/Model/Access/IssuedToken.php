<?php

declare(strict_types=1);

namespace App\Model\Access;

/**
 * Nově vydaný odkaz – jediný okamžik, kdy je k dispozici token v čitelné podobě.
 */
final readonly class IssuedToken
{
	public function __construct(
		public MeetingAccessToken $accessToken,
		#[\SensitiveParameter]
		public string $token,
	) {}
}
