<?php

declare(strict_types=1);

namespace App\ApiModule\Mcp\Tool;

use App\ApiModule\Mcp\Arguments;
use App\ApiModule\Mcp\McpContext;
use App\ApiModule\Mcp\McpTool;
use App\ApiModule\Mcp\ToolDefinition;
use App\Core\Security\Resource;
use App\Model\People\Body;
use App\Model\People\Membership;
use App\Model\People\PeopleFacade;
use App\Model\People\Person;

final readonly class ListPeopleTool implements McpTool
{
	public function __construct(
		private PeopleFacade $people,
	) {}

	public function definition(): ToolDefinition
	{
		return new ToolDefinition(
			'list_people',
			'Osoby a orgány',
			'Členové orgánů aktuálního volebního období s funkcí (starosta, místostarosta, předseda…) a seznam všech osob s ID (pro předkladatele, odpovědné osoby a úkoly). Kontakty se neuvádějí.',
			[],
			[],
			Resource::Member,
		);
	}

	public function call(Arguments $arguments, McpContext $context): array
	{
		$term = $this->people->findCurrentTerm($context->municipalityId());
		$today = $context->now();

		return [
			'term' => $term?->getName(),
			'bodies' => $term === null ? [] : array_map(static fn(Body $body): array => [
				'id' => $body->getId(),
				'name' => $body->getName(),
				'members' => array_map(static fn(Membership $membership): array => [
					'person_id' => $membership->getPerson()->getId(),
					'name' => $membership->getPerson()->getFullName(),
					'function' => $membership->getFunction()->label(),
				], $body->getMembersAt($today)),
			], $this->people->findBodies($term->getId())),
			'people' => array_map(static fn(Person $person): array => [
				'id' => $person->getId(),
				'name' => $person->getFullName(),
			], $this->people->findPersons($context->municipalityId())),
		];
	}
}
