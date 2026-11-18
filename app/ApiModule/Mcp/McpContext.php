<?php

declare(strict_types=1);

namespace App\ApiModule\Mcp;

use App\Core\Security\Privilege;
use App\Core\Security\Resource;
use App\Model\AiAccess\AccessScope;
use App\Model\AiAccess\AiAccessToken;
use App\Model\Municipality\Module;
use App\Model\Municipality\Municipality;
use App\Model\Shared\TenantOwned;
use App\Model\User\User;
use Nette\Security\Authorizator;
use Psr\Clock\ClockInterface;

/**
 * Kdo volá server MCP: uživatel (role, obec) a rozsah jeho připojení. Nástroje přes něj ověřují oprávnění.
 */
final readonly class McpContext
{
	public function __construct(
		public AiAccessToken $token,
		private Authorizator $authorizator,
		private ClockInterface $clock,
	) {}

	public function user(): User
	{
		return $this->token->getUser();
	}

	public function userId(): int
	{
		return $this->user()->getId();
	}

	public function municipality(): Municipality
	{
		return $this->token->getMunicipality();
	}

	public function municipalityId(): int
	{
		return $this->municipality()->getId();
	}

	public function can(Resource $resource, Privilege $privilege = Privilege::View): bool
	{
		return $this->authorizator->isAllowed($this->user()->getRole()->value, $resource->value, $privilege->value);
	}

	public function canWrite(): bool
	{
		return $this->token->allows(AccessScope::Write);
	}

	public function moduleEnabled(Module $module): bool
	{
		return $this->municipality()->isModuleEnabled($module);
	}

	/**
	 * Smí uživatel tímto připojením nástroj použít (oprávnění role, modul obce, rozsah připojení).
	 */
	public function allows(ToolDefinition $definition): bool
	{
		return ($definition->module === null || $this->moduleEnabled($definition->module))
			&& $this->can($definition->resource, $definition->privilege)
			&& (!$definition->writes || $this->canWrite());
	}

	/**
	 * Druhá vrstva izolace obcí vedle TenantFilteru (stejně jako assertOwned v presenterech).
	 *
	 * @template T of TenantOwned
	 * @param T $entity
	 * @return T
	 * @throws ToolException
	 */
	public function owned(TenantOwned $entity): TenantOwned
	{
		if ($entity->getMunicipality()->getId() !== $this->municipalityId()) {
			throw new ToolException('Záznam neexistuje.');
		}

		return $entity;
	}

	/**
	 * @throws ToolException
	 */
	public function requireModule(Module $module): void
	{
		if (!$this->moduleEnabled($module)) {
			throw new ToolException("Modul „{$module->label()}“ má obec vypnutý.");
		}
	}

	public function now(): \DateTimeImmutable
	{
		return $this->clock->now();
	}

	public function timezone(): \DateTimeZone
	{
		return $this->clock->now()->getTimezone();
	}
}
