<?php

declare(strict_types=1);

namespace App\ApiModule\OAuth;

use App\ApiModule\ApiResponses;
use App\Model\AiAccess\AiAccessToken;
use App\Model\AiAccess\IssuedAccessToken;
use App\Model\AiAccess\OAuthClient;
use App\Model\AiAccess\OAuthException;
use App\Model\AiAccess\OAuthFacade;
use Nette\Application\UI\Presenter;
use Nette\Http\IResponse;

/**
 * Autorizační server OAuth 2.1 pro připojení AI asistentů (MCP authorization):
 * metadata (RFC 9728, RFC 8414), registrace aplikace (RFC 7591), token, odvolání (RFC 7009).
 * Přihlášení a souhlas uživatele je stránka Admin:AiConnection:authorize (/oauth/authorize).
 */
final class OAuthPresenter extends Presenter
{
	public function __construct(
		private readonly OAuthFacade $oauth,
	) {
		parent::__construct();
	}

	protected function startup(): void
	{
		parent::startup();
		ApiResponses::cors($this->getHttpResponse());
		$this->getHttpResponse()->setHeader('Cache-Control', 'no-store');
		if ($this->getHttpRequest()->getMethod() === 'OPTIONS') {
			$this->getHttpResponse()->setCode(IResponse::S204_NoContent);
			$this->terminate();
		}
	}

	/**
	 * GET /.well-known/oauth-protected-resource[/mcp]
	 */
	public function actionProtectedResource(): void
	{
		$request = $this->getHttpRequest();
		$this->sendJson([
			'resource' => ApiResponses::mcpUrl($request),
			'authorization_servers' => [ApiResponses::baseUrl($request)],
			'scopes_supported' => ['read', 'write'],
			'bearer_methods_supported' => ['header'],
			'resource_name' => 'Konšel',
		]);
	}

	/**
	 * GET /.well-known/oauth-authorization-server
	 */
	public function actionMetadata(): void
	{
		$base = ApiResponses::baseUrl($this->getHttpRequest());
		$this->sendJson([
			'issuer' => $base,
			'authorization_endpoint' => "{$base}/oauth/authorize",
			'token_endpoint' => "{$base}/oauth/token",
			'registration_endpoint' => "{$base}/oauth/register",
			'revocation_endpoint' => "{$base}/oauth/revoke",
			'response_types_supported' => ['code'],
			'response_modes_supported' => ['query'],
			'grant_types_supported' => ['authorization_code', 'refresh_token'],
			'code_challenge_methods_supported' => ['S256'],
			'token_endpoint_auth_methods_supported' => OAuthClient::AuthMethods,
			'revocation_endpoint_auth_methods_supported' => OAuthClient::AuthMethods,
			'scopes_supported' => ['read', 'write'],
			'authorization_response_iss_parameter_supported' => true,
		]);
	}

	/**
	 * POST /oauth/register – Dynamic Client Registration (veřejná, jak vyžaduje MCP).
	 */
	public function actionRegister(): void
	{
		$this->requirePost();
		$data = json_decode($this->getHttpRequest()->getRawBody() ?? '', true);
		if (!is_array($data)) {
			$this->sendError('invalid_client_metadata', 'Body must be a JSON object.');
		}

		$redirectUris = $data['redirect_uris'] ?? null;
		$grantTypes = $data['grant_types'] ?? ['authorization_code'];
		$responseTypes = $data['response_types'] ?? ['code'];
		$authMethod = $data['token_endpoint_auth_method'] ?? 'client_secret_basic';
		$redirectUris = self::stringList($redirectUris) ?? $this->sendError('invalid_redirect_uri', 'redirect_uris must be a list of strings.');
		$grantTypes = self::stringList($grantTypes) ?? [];
		if (array_diff($grantTypes, ['authorization_code', 'refresh_token']) !== [] || !in_array('authorization_code', $grantTypes, true)) {
			$this->sendError('invalid_client_metadata', 'Only authorization_code and refresh_token grants are supported.');
		}

		if ($responseTypes !== ['code'] || !is_string($authMethod)) {
			$this->sendError('invalid_client_metadata', 'Only response_type "code" is supported.');
		}

		$name = $data['client_name'] ?? null;
		try {
			$registered = $this->oauth->registerClient(is_string($name) ? $name : '', $redirectUris, $authMethod);
		} catch (OAuthException $e) {
			$this->sendError($e->error, $e->getMessage());
		}

		$client = $registered->client;
		$this->getHttpResponse()->setCode(IResponse::S201_Created);
		$this->sendJson([
			'client_id' => $client->getClientId(),
			'client_id_issued_at' => $client->getCreatedAt()->getTimestamp(),
			...($registered->secret === null ? [] : [
				'client_secret' => $registered->secret,
				'client_secret_expires_at' => 0,
			]),
			'client_name' => $client->getName(),
			'redirect_uris' => $client->getRedirectUris(),
			'grant_types' => ['authorization_code', 'refresh_token'],
			'response_types' => ['code'],
			'token_endpoint_auth_method' => $client->getAuthMethod(),
		]);
	}

	/**
	 * POST /oauth/token – výměna kódu za token a obnovení tokenu.
	 */
	public function actionToken(): void
	{
		$this->requirePost();
		[$clientId, $secret] = $this->clientCredentials();
		try {
			$issued = match ($this->post('grant_type')) {
				'authorization_code' => $this->oauth->exchangeCode(
					$clientId,
					$secret,
					$this->post('code') ?? throw new OAuthException('invalid_request', 'Missing code.'),
					$this->post('redirect_uri') ?? throw new OAuthException('invalid_request', 'Missing redirect_uri.'),
					$this->post('code_verifier') ?? throw new OAuthException('invalid_request', 'Missing code_verifier (PKCE).'),
				),
				'refresh_token' => $this->oauth->refresh(
					$clientId,
					$secret,
					$this->post('refresh_token') ?? throw new OAuthException('invalid_request', 'Missing refresh_token.'),
				),
				default => throw new OAuthException('unsupported_grant_type', 'Supported grants: authorization_code, refresh_token.'),
			};
		} catch (OAuthException $e) {
			$this->sendError($e->error, $e->getMessage(), $e->error === 'invalid_client' ? IResponse::S401_Unauthorized : IResponse::S400_BadRequest);
		}

		$this->sendTokens($issued);
	}

	/**
	 * POST /oauth/revoke – odvolání tokenu aplikací (např. při odpojení v Claude).
	 */
	public function actionRevoke(): void
	{
		$this->requirePost();
		$token = $this->post('token');
		if ($token !== null) {
			$this->oauth->revokeToken($token);
		}

		$this->sendJson(new \stdClass());
	}

	private function sendTokens(IssuedAccessToken $issued): never
	{
		$this->getHttpResponse()->setHeader('Pragma', 'no-cache');
		$this->sendJson([
			'access_token' => $issued->accessToken,
			'token_type' => 'Bearer',
			'expires_in' => AiAccessToken::OAuthAccessSeconds,
			'refresh_token' => $issued->refreshToken,
			'scope' => $issued->token->getScope()->oauthScope(),
		]);
	}

	/**
	 * client_secret_basic (hlavička) nebo client_secret_post / veřejný klient (tělo).
	 *
	 * @return array{string, ?string}
	 */
	private function clientCredentials(): array
	{
		$header = $this->getHttpRequest()->getHeader('Authorization');
		if ($header !== null && preg_match('~^Basic\s+(\S+)$~i', $header, $match) === 1) {
			$decoded = base64_decode($match[1], true);
			if ($decoded !== false && str_contains($decoded, ':')) {
				[$id, $secret] = explode(':', $decoded, 2);

				return [urldecode($id), urldecode($secret)];
			}
		}

		return [$this->post('client_id') ?? '', $this->post('client_secret')];
	}

	private function post(string $name): ?string
	{
		$value = $this->getHttpRequest()->getPost($name);

		return is_string($value) && $value !== '' ? $value : null;
	}

	private function requirePost(): void
	{
		if ($this->getHttpRequest()->getMethod() !== 'POST') {
			$this->getHttpResponse()->setHeader('Allow', 'POST, OPTIONS');
			$this->sendError('invalid_request', 'Use POST.', IResponse::S405_MethodNotAllowed);
		}
	}

	private function sendError(string $error, string $description, int $code = IResponse::S400_BadRequest): never
	{
		$this->getHttpResponse()->setCode($code);
		$this->sendJson([
			'error' => $error,
			'error_description' => $description,
		]);
	}

	/**
	 * @return list<string>|null
	 */
	private static function stringList(mixed $value): ?array
	{
		if (!is_array($value) || !array_is_list($value)) {
			return null;
		}

		$strings = array_values(array_filter($value, is_string(...)));

		return count($strings) === count($value) ? $strings : null;
	}
}
