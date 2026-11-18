<?php

declare(strict_types=1);

namespace App\Core\Config;

/**
 * Konfigurace z proměnných prostředí KONSEL_* (produkční Docker, stack v Portaineru).
 * Přebíjí common.neon i local.neon; nevyplněná proměnná nic nemění.
 */
final class EnvironmentConfig
{
	public const array Variables = [
		'KONSEL_APP_URL',
		'KONSEL_ENCRYPTION_KEY',
		'KONSEL_MAIL_FROM',
		'KONSEL_SMTP_HOST',
		'KONSEL_SMTP_PORT',
		'KONSEL_SMTP_USERNAME',
		'KONSEL_SMTP_PASSWORD',
		'KONSEL_SMTP_ENCRYPTION',
		'KONSEL_TRUSTED_PROXIES',
		'KONSEL_ERROR_EMAIL',
	];

	/**
	 * Čte proměnné po jedné: getenv() bez argumentu v Apache (mod_php) nevrací prostředí procesu spolehlivě.
	 *
	 * @param string $keyFile klíč vygenerovaný při prvním startu kontejneru, když není KONSEL_ENCRYPTION_KEY
	 * @return array<string, mixed>
	 */
	public static function read(string $keyFile): array
	{
		$env = [];
		foreach (self::Variables as $name) {
			$value = getenv($name);
			if ($value !== false) {
				$env[$name] = $value;
			}
		}

		$key = is_file($keyFile) ? file_get_contents($keyFile) : false;

		return self::fromVariables($env, $key === false ? null : $key);
	}

	/**
	 * @param array<string, string> $env
	 * @return array<string, mixed> konfigurace Nette (sekce parameters, http, tracy)
	 * @throws \InvalidArgumentException neplatná hodnota proměnné
	 */
	public static function fromVariables(array $env, ?string $keyFileContent = null): array
	{
		$value = static function (string $name) use ($env): ?string {
			$value = trim($env[$name] ?? '');

			return $value === '' ? null : $value;
		};

		$parameters = [];
		$appUrl = $value('KONSEL_APP_URL');
		if ($appUrl !== null) {
			$parameters['appUrl'] = rtrim($appUrl, '/');
		}

		$key = $value('KONSEL_ENCRYPTION_KEY') ?? ($keyFileContent === null ? null : trim($keyFileContent));
		if ($key !== null && $key !== '') {
			$parameters['encryptionKey'] = $key;
		}

		$mailFrom = $value('KONSEL_MAIL_FROM');
		if ($mailFrom !== null) {
			$parameters['mailFrom'] = $mailFrom;
		}

		$smtp = self::smtp($value);
		if ($smtp !== []) {
			$parameters['smtp'] = $smtp;
		}

		$config = $parameters === [] ? [] : [
			'parameters' => $parameters,
		];

		$http = [];
		$proxies = $value('KONSEL_TRUSTED_PROXIES');
		if ($proxies !== null) {
			$list = preg_split('/[\s,]+/', $proxies);
			$http['proxy'] = array_values(array_filter(
				$list === false ? [] : $list,
				static fn(string $proxy): bool => $proxy !== '',
			));
		}

		if ($appUrl !== null && strncmp($appUrl, 'https://', 8) === 0) {
			$http['headers'] = [
				'Strict-Transport-Security' => 'max-age=31536000',
			];
		}

		if ($http !== []) {
			$config['http'] = $http;
		}

		$errorEmail = $value('KONSEL_ERROR_EMAIL');
		if ($errorEmail !== null) {
			$config['tracy'] = [
				'email' => $errorEmail,
				'fromEmail' => $mailFrom ?? 'noreply@konsel.matyho.cz',
			];
		}

		return $config;
	}

	/**
	 * @param \Closure(string): ?string $value
	 * @return array<string, int|string|null>
	 */
	private static function smtp(\Closure $value): array
	{
		$smtp = [];
		foreach ([
			'host' => 'KONSEL_SMTP_HOST',
			'username' => 'KONSEL_SMTP_USERNAME',
			'password' => 'KONSEL_SMTP_PASSWORD',
		] as $key => $name) {
			$item = $value($name);
			if ($item !== null) {
				$smtp[$key] = $item;
			}
		}

		$port = $value('KONSEL_SMTP_PORT');
		if ($port !== null) {
			if (preg_match('/^\d{1,5}$/', $port) !== 1) {
				throw new \InvalidArgumentException("KONSEL_SMTP_PORT must be a number, '{$port}' given.");
			}

			$smtp['port'] = (int) $port;
		}

		$encryption = $value('KONSEL_SMTP_ENCRYPTION');
		if ($encryption !== null) {
			$encryption = strtolower($encryption);
			if (!in_array($encryption, ['tls', 'ssl', 'none'], true)) {
				throw new \InvalidArgumentException("KONSEL_SMTP_ENCRYPTION must be tls, ssl or none, '{$encryption}' given.");
			}

			$smtp['encryption'] = $encryption === 'none' ? null : $encryption;
		}

		return $smtp;
	}
}
