<?php

declare(strict_types=1);

namespace App\ApiModule\Mcp;

use App\Model\Shared\Exception\ConcurrencyException;
use App\Model\Shared\Exception\DomainException;
use App\Model\Shared\Exception\ValidationException;
use Tracy\ILogger;

/**
 * Server Model Context Protocol (JSON-RPC 2.0 nad Streamable HTTP, odpovědi jako JSON bez SSE).
 * Umí initialize, ping, tools/list a tools/call; relace (Mcp-Session-Id) nepoužívá – každý požadavek nese token.
 */
final readonly class McpServer
{
	/**
	 * Podporované verze protokolu, nejnovější první.
	 */
	public const array ProtocolVersions = ['2025-11-25', '2025-06-18', '2025-03-26', '2024-11-05'];

	public const string Version = '1.0';

	public function __construct(
		private McpToolRegistry $tools,
		private ILogger $logger,
	) {}

	/**
	 * Zpracuje jednu zprávu JSON-RPC. Vrací odpověď, nebo null u notifikace (HTTP 202 bez těla).
	 *
	 * @return array<string, mixed>|null
	 */
	public function handle(mixed $message, McpContext $context): ?array
	{
		if (!is_array($message)) {
			return self::error(null, RpcException::InvalidRequest, 'Invalid Request');
		}

		// odpověď klienta na náš požadavek – žádné neposíláme, takže jen potvrdit přijetí
		if (!isset($message['method']) && (array_key_exists('result', $message) || array_key_exists('error', $message))) {
			return null;
		}

		$rawId = $message['id'] ?? null;
		$id = is_string($rawId) || is_int($rawId) ? $rawId : null;
		$isNotification = !array_key_exists('id', $message);
		$method = $message['method'] ?? null;
		if (($message['jsonrpc'] ?? null) !== '2.0' || !is_string($method) || (!$isNotification && $id === null)) {
			return self::error($id, RpcException::InvalidRequest, 'Invalid Request');
		}

		$params = $message['params'] ?? [];
		if (!is_array($params)) {
			return $isNotification ? null : self::error($id, RpcException::InvalidParams, 'Params must be an object.');
		}

		try {
			$result = match ($method) {
				'initialize' => $this->initialize($params, $context),
				'ping' => new \stdClass(),
				'tools/list' => [
					'tools' => array_map(static fn(McpTool $tool): array => $tool->definition()->toArray(), $this->tools->availableFor($context)),
				],
				'tools/call' => $this->callTool($params, $context),
				default => strncmp($method, 'notifications/', 14) === 0 ? null : throw new RpcException("Method not found: {$method}", RpcException::MethodNotFound),
			};
		} catch (RpcException $e) {
			return $isNotification ? null : self::error($id, $e->getCode(), $e->getMessage());
		}

		if ($isNotification) {
			return null;
		}

		return [
			'jsonrpc' => '2.0',
			'id' => $id,
			'result' => $result ?? new \stdClass(),
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function error(string|int|null $id, int $code, string $message): array
	{
		return [
			'jsonrpc' => '2.0',
			'id' => $id,
			'error' => [
				'code' => $code,
				'message' => $message,
			],
		];
	}

	/**
	 * @param array<mixed> $params
	 * @return array<string, mixed>
	 */
	private function initialize(array $params, McpContext $context): array
	{
		$requested = $params['protocolVersion'] ?? null;

		return [
			'protocolVersion' => in_array($requested, self::ProtocolVersions, true) ? $requested : self::ProtocolVersions[0],
			'capabilities' => [
				'tools' => [
					'listChanged' => false,
				],
			],
			'serverInfo' => [
				'name' => 'konsel',
				'title' => 'Konšel',
				'version' => self::Version,
			],
			'instructions' => $this->instructions($context),
		];
	}

	/**
	 * @param array<mixed> $params
	 * @return array<string, mixed>
	 */
	private function callTool(array $params, McpContext $context): array
	{
		$name = $params['name'] ?? null;
		$arguments = $params['arguments'] ?? [];
		if (!is_string($name) || !is_array($arguments)) {
			throw new RpcException('tools/call needs name and arguments object.', RpcException::InvalidParams);
		}

		$tool = $this->tools->find($name) ?? throw new RpcException("Unknown tool: {$name}", RpcException::InvalidParams);
		$definition = $tool->definition();
		if (!$context->allows($definition)) {
			return self::toolError($definition->writes && !$context->canWrite()
				? 'Toto připojení má jen oprávnění ke čtení. Změny povolíte při novém připojení nebo osobním tokenem s rozsahem „čtení i změny“.'
				: 'Na tento nástroj nemáte oprávnění, nebo je jeho modul v obci vypnutý.');
		}

		try {
			$data = $tool->call(new Arguments($arguments), $context);
		} catch (ToolException $e) {
			return self::toolError($e->getMessage());
		} catch (ValidationException $e) {
			return self::toolError($e->getMessage() . ($e->field === null ? '' : " (pole {$e->field})"));
		} catch (DomainException|ConcurrencyException $e) {
			return self::toolError($e->getMessage());
		} catch (\Throwable $e) {
			$this->logger->log($e, ILogger::EXCEPTION);

			return self::toolError('Interní chyba serveru Konšel, podrobnosti jsou v logu aplikace.');
		}

		return [
			'content' => [[
				'type' => 'text',
				'text' => self::json($data),
			]],
			'isError' => false,
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function toolError(string $message): array
	{
		return [
			'content' => [[
				'type' => 'text',
				'text' => $message,
			]],
			'isError' => true,
		];
	}

	/**
	 * @param array<mixed> $data
	 */
	private static function json(array $data): string
	{
		return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
	}

	private function instructions(McpContext $context): string
	{
		$user = $context->user();

		return <<<TXT
			Konšel je systém obce {$context->municipality()->getName()} pro přípravu a vedení zasedání zastupitelstva, rady a výborů: program → podklady → pozvánka → průběh (docházka, hlasování) → usnesení → zápis → archiv.
			Pracujete jménem uživatele {$user->getName()} (role: {$user->getRole()->label()}), rozsah připojení: {$context->token->getScope()->label()}. Vidíte a smíte jen to, co tento uživatel; změny se zapisují do historie pod jeho jménem s poznámkou „AI“.

			Pojmy: orgán (zastupitelstvo/rada/výbor), zasedání (meeting), bod programu (agenda item, číslo např. 5 nebo podbod 5.1), předkladatel, důvodová zpráva (description), návrh usnesení (proposed_resolution – text, o kterém se hlasuje, obvykle „Zastupitelstvo obce … schvaluje …“), usnesení (číslo např. 12/2/3), úkol z usnesení, plán (dlouhodobý záměr obce), připomínka (komentář k bodu).
			Postup: začněte nástrojem get_overview, ID zasedání, bodů a usnesení pak berte z výsledků (nehádejte je). Pro dohledání v historii použijte search.
			Data jsou úřední záznamy – necitujte nic, co ve výsledcích není. Program lze měnit jen u zasedání v přípravě; hlasování, docházku, schválení zápisu ani rozesílání pozvánek přes AI dělat nelze. Časy jsou v časovém pásmu Europe/Prague.
			TXT;
	}
}
