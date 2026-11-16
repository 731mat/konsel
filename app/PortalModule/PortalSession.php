<?php

declare(strict_types=1);

namespace App\PortalModule;

use Nette\Http\Session;
use Nette\Http\SessionSection;

/**
 * Přihlášení zastupitele do portálu: v session je jen ID odkazu, platnost se ověřuje při každém požadavku.
 */
final readonly class PortalSession
{
	private const string Section = 'konsel.portal';

	public function __construct(
		private Session $session,
	) {}

	public function login(int $tokenId): void
	{
		$this->session->regenerateId();
		$this->section()->set('tokenId', $tokenId);
	}

	public function logout(): void
	{
		$this->section()->remove();
	}

	public function getTokenId(): ?int
	{
		$tokenId = $this->section()->get('tokenId');

		return is_int($tokenId) ? $tokenId : null;
	}

	private function section(): SessionSection
	{
		return $this->session->getSection(self::Section);
	}
}
