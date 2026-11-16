<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Core\Security\Authenticator;
use App\Model\User\User as AppUser;
use Nette\Application\IPresenterFactory;
use Nette\Application\Request;
use Nette\Application\Response;
use Nette\Application\Responses\RedirectResponse;
use Nette\Application\Responses\TextResponse;
use Nette\Application\UI\Presenter;
use Nette\Bridges\ApplicationLatte\Template;
use Nette\DI\Container;
use Nette\Http\IRequest;
use Nette\Http\Request as HttpRequest;
use Nette\Security\SimpleIdentity;
use Nette\Security\User;
use Tester\Assert;

/**
 * Spouští presentery v integračních testech (bez HTTP serveru).
 */
final readonly class PresenterTester
{
	public function __construct(
		private Container $container,
	) {}

	public function loginAs(AppUser $user): void
	{
		$identity = $this->container->getByType(Authenticator::class)->wakeupIdentity(new SimpleIdentity($user->getId()));
		Assert::notNull($identity);
		assert($identity !== null);
		$this->container->getByType(User::class)->login($identity);
	}

	/**
	 * @param array<string, mixed> $params
	 * @param array<string, mixed> $post
	 * @param array<string, mixed> $files
	 */
	public function run(
		string $presenter,
		string $action = 'default',
		array $params = [],
		array $post = [],
		array $files = [],
	): Response {
		$method = $post === [] && $files === [] ? 'GET' : 'POST';
		$this->useHttpMethod($method);
		$instance = $this->container->getByType(IPresenterFactory::class)->createPresenter($presenter);
		Assert::type(Presenter::class, $instance);
		assert($instance instanceof Presenter);
		$instance->autoCanonicalize = false;

		$request = new Request($presenter, $method, [
			'action' => $action,
		] + $params, $post, $files);

		return $instance->run($request);
	}

	/**
	 * @param array<string, mixed> $params
	 */
	public function render(string $presenter, string $action = 'default', array $params = []): string
	{
		$response = $this->run($presenter, $action, $params);
		Assert::type(TextResponse::class, $response);
		assert($response instanceof TextResponse);
		$source = $response->getSource();

		return $source instanceof Template ? $source->renderToString() : (is_string($source) ? $source : '');
	}

	/**
	 * Odešle formulář; vrací odpověď (RedirectResponse při úspěchu, TextResponse s chybami).
	 *
	 * @param array<string, mixed> $values
	 * @param array<string, mixed> $files
	 * @param array<string, mixed> $params
	 */
	public function submitForm(
		string $presenter,
		string $formName,
		array $values,
		string $action = 'default',
		array $files = [],
		array $params = [],
	): Response {
		return $this->run($presenter, $action, $params + [
			'do' => "{$formName}-submit",
		], $values + [
			'_submit' => '1',
		], $files);
	}

	/**
	 * #[Requires(methods: 'POST')] kontroluje metodu HTTP požadavku (ne aplikačního) – v testu ji podle potřeby vyměníme.
	 */
	private function useHttpMethod(string $method): void
	{
		$current = $this->container->getByType(IRequest::class);
		if ($current->getMethod() === $method) {
			return;
		}

		$name = $this->container->findByType(IRequest::class)[0] ?? throw new \LogicException('Missing HTTP request service.');
		$this->container->removeService($name);
		$this->container->addService($name, new HttpRequest(
			$current->getUrl(),
			headers: $current->getHeaders(),
			method: $method,
			remoteAddress: $current->getRemoteAddress(),
		));
	}

	public static function assertRedirect(Response $response): RedirectResponse
	{
		Assert::type(RedirectResponse::class, $response);
		assert($response instanceof RedirectResponse);

		return $response;
	}

	public static function renderResponse(Response $response): string
	{
		Assert::type(TextResponse::class, $response);
		assert($response instanceof TextResponse);
		$source = $response->getSource();

		return $source instanceof Template ? $source->renderToString() : (is_string($source) ? $source : '');
	}
}
