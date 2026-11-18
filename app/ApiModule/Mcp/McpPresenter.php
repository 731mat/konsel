<?php

declare(strict_types=1);

namespace App\ApiModule\Mcp;

use App\ApiModule\ApiResponses;
use App\Core\Tenancy\CurrentMunicipality;
use App\Model\AiAccess\AiAccessFacade;
use App\Model\Audit\AuditActor;
use App\Model\Audit\AuditContext;
use App\Model\Shared\Exception\AccessDeniedException;
use Nette\Application\UI\Presenter;
use Nette\Http\IResponse;
use Nette\Security\Authorizator;
use Nette\Utils\Strings;
use Psr\Clock\ClockInterface;

/**
 * POST /mcp – server MCP pro AI asistenty (Streamable HTTP). Přihlášení tokenem v hlavičce Authorization: Bearer
 * (osobní token nebo token z OAuth). Bez tokenu odpoví 401 s odkazem na metadata pro přihlášení (RFC 9728).
 */
final class McpPresenter extends Presenter
{
	public function __construct(
		private readonly McpServer $server,
		private readonly AiAccessFacade $aiAccess,
		private readonly CurrentMunicipality $currentMunicipality,
		private readonly AuditContext $auditContext,
		private readonly Authorizator $authorizator,
		private readonly ClockInterface $clock,
	) {
		parent::__construct();
	}

	public function actionDefault(): void
	{
		$request = $this->getHttpRequest();
		$response = $this->getHttpResponse();
		ApiResponses::cors($response);
		$response->setHeader('Cache-Control', 'no-store');

		if ($request->getMethod() === 'OPTIONS') {
			$response->setCode(IResponse::S204_NoContent);
			$this->terminate();
		}

		if ($request->getMethod() !== 'POST') {
			// server neposílá zprávy sám (žádný SSE stream) a relace nemá
			$response->setCode(IResponse::S405_MethodNotAllowed);
			$response->setHeader('Allow', 'POST, OPTIONS');
			$this->sendJson([
				'error' => 'Use POST with JSON-RPC messages (MCP Streamable HTTP).',
			]);
		}

		$bearer = self::bearer($request->getHeader('Authorization'));
		try {
			$token = $this->aiAccess->authenticate($bearer ?? throw new AccessDeniedException('Chybí přihlášení (hlavička Authorization: Bearer).'));
		} catch (AccessDeniedException $e) {
			$response->setCode(IResponse::S401_Unauthorized);
			$response->setHeader('WWW-Authenticate', ApiResponses::bearerChallenge($request, $bearer !== null));
			$this->sendJson([
				'error' => 'unauthorized',
				'error_description' => $e->getMessage(),
			]);
		}

		$user = $token->getUser();
		$this->currentMunicipality->set($token->getMunicipality());
		$this->auditContext->setActor(new AuditActor(
			Strings::truncate("{$user->getName()} (AI: {$token->getName()})", 150),
			$user->getId(),
			$request->getRemoteAddress(),
		));

		$context = new McpContext($token, $this->authorizator, $this->clock);
		try {
			$message = json_decode($request->getRawBody() ?? '', true, 64, JSON_THROW_ON_ERROR);
		} catch (\JsonException) {
			$response->setCode(IResponse::S400_BadRequest);
			$this->sendJson(McpServer::error(null, RpcException::ParseError, 'Parse error'));
		}

		// dávka (verze 2025-03-26) – pole zpráv
		if (is_array($message) && $message !== [] && array_is_list($message)) {
			$responses = array_values(array_filter(
				array_map(fn(mixed $item): ?array => $this->server->handle($item, $context), $message),
				static fn(?array $response): bool => $response !== null,
			));
			$this->respond($responses === [] ? null : $responses);
		}

		$this->respond($this->server->handle($message, $context));
	}

	/**
	 * @param array<mixed>|null $payload
	 */
	private function respond(?array $payload): never
	{
		if ($payload === null) {
			$this->getHttpResponse()->setCode(IResponse::S202_Accepted);
			$this->terminate();
		}

		$this->sendJson($payload);
	}

	private static function bearer(?string $header): ?string
	{
		return $header !== null && preg_match('~^Bearer\s+(\S+)$~i', trim($header), $match) === 1 ? $match[1] : null;
	}
}
