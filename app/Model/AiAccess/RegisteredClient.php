<?php

declare(strict_types=1);

namespace App\Model\AiAccess;

final readonly class RegisteredClient
{
	public function __construct(
		public OAuthClient $client,
		#[\SensitiveParameter]
		public ?string $secret,
	) {}
}
