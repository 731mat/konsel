<?php

declare(strict_types=1);

namespace App\ApiModule\Mcp;

/**
 * Všechny nástroje serveru MCP (registrují se samy podle konvence ApiModule/Mcp/Tool/*Tool).
 */
final readonly class McpToolRegistry
{
	/**
	 * @var array<string, McpTool>
	 */
	private array $tools;

	/**
	 * @param array<McpTool> $tools
	 */
	public function __construct(array $tools)
	{
		$indexed = [];
		foreach ($tools as $tool) {
			$name = $tool->definition()->name;
			if (isset($indexed[$name])) {
				throw new \LogicException("Duplicate MCP tool name '{$name}'.");
			}

			$indexed[$name] = $tool;
		}

		// čtení dřív než změny, jinak abecedně – pořadí vidí AI v tools/list
		uasort($indexed, static fn(McpTool $a, McpTool $b): int => [$a->definition()->writes, $a->definition()->name] <=> [$b->definition()->writes, $b->definition()->name]);
		$this->tools = $indexed;
	}

	public function find(string $name): ?McpTool
	{
		return $this->tools[$name] ?? null;
	}

	/**
	 * Nástroje, které smí uživatel tímto připojením použít.
	 *
	 * @return list<McpTool>
	 */
	public function availableFor(McpContext $context): array
	{
		return array_values(array_filter($this->tools, static fn(McpTool $tool): bool => $context->allows($tool->definition())));
	}

	/**
	 * @return list<McpTool>
	 */
	public function all(): array
	{
		return array_values($this->tools);
	}
}
