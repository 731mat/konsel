<?php

declare(strict_types=1);

namespace App\AdminModule\AiConnection;

use App\AdminModule\SecuredPresenter;
use App\ApiModule\ApiResponses;
use App\Core\Security\Allowed;
use App\Core\Security\Privilege;
use App\Core\Security\RequiresModule;
use App\Core\Security\Resource;
use App\Model\AiAccess\AccessScope;
use App\Model\AiAccess\AiAccessFacade;
use App\Model\AiAccess\AiAccessToken;
use App\Model\AiAccess\IssuedAccessToken;
use App\Model\AiAccess\OAuthClient;
use App\Model\AiAccess\OAuthException;
use App\Model\AiAccess\OAuthFacade;
use App\Model\AiAccess\Pkce;
use App\Model\Municipality\Module;
use App\Model\Shared\Exception\ValidationException;
use App\UI\Form\FormErrors;
use App\UI\Form\FormFactory;
use Nette\Application\Attributes\Requires;
use Nette\Application\UI\Form;
use Nette\Http\Url;

/**
 * Připojení AI asistentů (server MCP): adresa serveru a návod, připojené aplikace, osobní tokeny,
 * u správce přehled připojení všech uživatelů obce. Akce authorize = souhlas s připojením aplikace (OAuth).
 */
#[Allowed(Resource::Dashboard)]
final class AiConnectionPresenter extends SecuredPresenter
{
	private ?IssuedAccessToken $issued = null;

	private ?OAuthClient $client = null;

	private ?string $redirectUri = null;

	private ?string $state = null;

	private ?string $codeChallenge = null;

	private ?string $authorizeError = null;

	public function __construct(
		private readonly AiAccessFacade $aiAccess,
		private readonly OAuthFacade $oauth,
		private readonly FormFactory $formFactory,
	) {
		parent::__construct();
	}

	#[RequiresModule(Module::AiConnections)]
	public function actionDefault(): void {}

	public function renderDefault(): void
	{
		$userId = $this->currentUserId();
		$canManageAll = $this->isAllowed(Resource::User, Privilege::Edit) && $this->currentMunicipality->isSet();
		$this->template->mcpUrl = ApiResponses::mcpUrl($this->getHttpRequest());
		$this->template->accountProblem = $this->aiAccess->accountProblem($userId);
		$this->template->connections = $this->aiAccess->findForUser($userId);
		$this->template->issued = $this->issued;
		$this->template->canManageAll = $canManageAll;
		$this->template->otherConnections = $canManageAll ? array_values(array_filter(
			$this->aiAccess->findForMunicipality($this->requireMunicipality()->getId()),
			static fn(AiAccessToken $token): bool => $token->getUser()->getId() !== $userId,
		)) : [];
	}

	/**
	 * Odpojení vlastního připojení; správce obce smí odpojit kohokoli z obce.
	 */
	#[RequiresModule(Module::AiConnections)]
	#[Requires(methods: 'POST', sameOrigin: true)]
	public function handleRevoke(int $tokenId): void
	{
		$token = $this->assertOwned($this->aiAccess->get($tokenId));
		if ($token->getUser()->getId() !== $this->currentUserId() && !$this->isAllowed(Resource::User, Privilege::Edit)) {
			$this->error('Toto připojení nemůžete odpojit.', 403);
		}

		$this->aiAccess->revoke($token->getId());
		$this->flashMessage("Připojení „{$token->getName()}“ bylo odpojeno, AI už k Konšelu nemá přístup.", 'success');
		$this->redirect('this');
	}

	/**
	 * GET /oauth/authorize – aplikace (Claude, ChatGPT…) žádá o přístup k účtu přihlášeného uživatele.
	 * Chyby klienta a adresy se ukážou tady (nelze bezpečně přesměrovat), ostatní se vrátí aplikaci.
	 */
	public function actionAuthorize(
		?string $response_type = null,
		?string $client_id = null,
		?string $redirect_uri = null,
		?string $state = null,
		?string $code_challenge = null,
		?string $code_challenge_method = null,
		?string $scope = null,
		?string $resource = null,
	): void {
		$client = $this->oauth->findClient($client_id ?? '');
		if ($client === null) {
			$this->authorizeError = 'Aplikaci se nepodařilo ověřit (neznámé client_id). Spusťte připojení v aplikaci znovu.';

			return;
		}

		$registered = $client->getRedirectUris();
		$redirectUri = $redirect_uri ?? (count($registered) === 1 ? $registered[0] : null);
		if ($redirectUri === null || !$client->allowsRedirectUri($redirectUri)) {
			$this->authorizeError = 'Návratová adresa aplikace neodpovídá její registraci. Spusťte připojení v aplikaci znovu.';

			return;
		}

		$this->client = $client;
		$this->redirectUri = $redirectUri;
		$this->state = $state;
		if ($response_type !== 'code') {
			$this->redirectToClient([
				'error' => 'unsupported_response_type',
			]);
		}

		if ($code_challenge_method !== 'S256' || $code_challenge === null || !Pkce::isValidChallenge($code_challenge)) {
			$this->redirectToClient([
				'error' => 'invalid_request',
				'error_description' => 'PKCE with S256 is required.',
			]);
		}

		if ($resource !== null && !in_array(rtrim($resource, '/'), [ApiResponses::mcpUrl($this->getHttpRequest()), ApiResponses::baseUrl($this->getHttpRequest())], true)) {
			$this->redirectToClient([
				'error' => 'invalid_target',
				'error_description' => 'Unknown resource.',
			]);
		}

		$this->authorizeError = $this->aiAccess->accountProblem($this->currentUserId());
		$this->codeChallenge = $code_challenge;
		$this->getComponent('consentForm')->setDefaults([
			'write' => AccessScope::fromOAuthScope($scope) === AccessScope::Write,
		]);
	}

	public function renderAuthorize(): void
	{
		$this->template->client = $this->client;
		$host = $this->redirectUri === null ? null : parse_url($this->redirectUri, PHP_URL_HOST);
		$this->template->redirectHost = is_string($host) && $host !== '' ? $host : $this->redirectUri;
		$this->template->authorizeError = $this->authorizeError;
	}

	protected function createComponentTokenForm(): Form
	{
		$form = $this->formFactory->create();
		$form->addText('name', 'Název')
			->setRequired('Zadejte název, podle kterého token poznáte.')
			->setMaxLength(120)
			->setDefaultValue('Claude Code')
			->setOption('description', 'Např. podle aplikace nebo počítače, kde token použijete.');
		$form->addRadioList('scope', 'Rozsah', [
			AccessScope::Read->value => 'Jen čtení – AI může vyhledávat a číst',
			AccessScope::Write->value => 'Čtení i změny – AI může navíc přidávat body programu, připomínky, plány a úkoly',
		])->setDefaultValue(AccessScope::Read->value);
		$form->addSelect('validDays', 'Platnost', [
			30 => '30 dní',
			90 => '90 dní',
			365 => '1 rok',
		])->setDefaultValue(90);
		$form->addSubmit('create', 'Vytvořit token');
		$form->onSuccess[] = function (Form $form): void {
			$this->requireModule($this->requireMunicipality(), Module::AiConnections);
			$data = $form->getValues(TokenFormData::class);
			try {
				$this->issued = $this->aiAccess->createPersonalToken($this->currentUserId(), $data->name, AccessScope::from($data->scope), $data->validDays);
			} catch (ValidationException $e) {
				FormErrors::apply($form, $e);

				return;
			}

			// token se ukáže jen teď – bez přesměrování (v DB je jen hash)
			$form->reset();
		};

		return $form;
	}

	protected function createComponentConsentForm(): Form
	{
		$form = $this->formFactory->create();
		$write = $form->addCheckbox('write', 'Povolit i změny – přidávat a upravovat body programu, připomínky, plány a úkoly');
		$form->addSubmit('allow', 'Povolit připojení');
		$deny = $form->addSubmit('deny', 'Zamítnout')
			->setValidationScope([])
			->setHtmlAttribute('class', 'btn btn-outline-secondary');
		$deny->onClick[] = function (): void {
			$this->redirectToClient([
				'error' => 'access_denied',
			]);
		};
		$form->onSuccess[] = function (Form $form) use ($write, $deny): void {
			if ($deny->isSubmittedBy() || $this->client === null || $this->redirectUri === null || $this->codeChallenge === null || $this->authorizeError !== null) {
				return;
			}

			try {
				$code = $this->oauth->authorize(
					$this->currentUserId(),
					$this->client->getClientId(),
					$this->redirectUri,
					$this->codeChallenge,
					$write->getValue() === true ? AccessScope::Write : AccessScope::Read,
				);
			} catch (OAuthException|ValidationException $e) {
				$form->addError($e->getMessage());

				return;
			}

			$this->redirectToClient([
				'code' => $code,
			]);
		};

		return $form;
	}

	/**
	 * Návrat do aplikace se state a vydavatelem (RFC 9207).
	 *
	 * @param array<string, string> $params
	 */
	private function redirectToClient(array $params): never
	{
		$url = new Url($this->redirectUri ?? $this->error());
		foreach ($params + [
			'state' => $this->state,
			'iss' => ApiResponses::baseUrl($this->getHttpRequest()),
		] as $name => $value) {
			if ($value !== null) {
				$url->setQueryParameter($name, $value);
			}
		}

		$this->redirectUrl($url->getAbsoluteUrl());
	}
}
