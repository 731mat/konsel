<?php

declare(strict_types=1);

namespace App\ApiModule\Mcp;

use App\Core\Security\Privilege;
use App\Core\Security\Resource;
use App\Model\Municipality\Module;

/**
 * Popis nástroje MCP pro AI (název, popis, JSON Schema vstupu) a podmínky, kdy ho uživatel smí použít.
 */
final readonly class ToolDefinition
{
	/**
	 * @param array<string, array<string, mixed>> $properties vlastnosti JSON Schema vstupu
	 * @param list<string> $required
	 */
	public function __construct(
		public string $name,
		public string $title,
		public string $description,
		public array $properties,
		public array $required,
		public Resource $resource,
		public Privilege $privilege = Privilege::View,
		public bool $writes = false,
		public ?Module $module = null,
	) {}

	/**
	 * @return array<string, mixed> položka odpovědi tools/list
	 */
	public function toArray(): array
	{
		return [
			'name' => $this->name,
			'title' => $this->title,
			'description' => $this->description,
			'inputSchema' => [
				'type' => 'object',
				'properties' => $this->properties === [] ? new \stdClass() : $this->properties,
				...($this->required === [] ? [] : [
					'required' => $this->required,
				]),
				'additionalProperties' => false,
			],
			'annotations' => [
				'title' => $this->title,
				'readOnlyHint' => !$this->writes,
				'destructiveHint' => false,
				'idempotentHint' => !$this->writes,
				'openWorldHint' => false,
			],
		];
	}
}
