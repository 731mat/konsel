<?php

declare(strict_types=1);

namespace App\ApiModule\Mcp;

/**
 * Nástroj serveru MCP. Pracuje jen přes fasády a každou entitu načtenou podle ID ověří přes McpContext::owned().
 */
interface McpTool
{
	public function definition(): ToolDefinition;

	/**
	 * @return array<string, mixed> výsledek pro AI (převede se na JSON)
	 * @throws ToolException chyba, kterou má AI vidět
	 * @throws \App\Model\Shared\Exception\DomainException porušení doménového pravidla (také se ukáže AI)
	 */
	public function call(Arguments $arguments, McpContext $context): array;
}
