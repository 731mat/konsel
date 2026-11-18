<?php

declare(strict_types=1);

use App\Core\Config\EnvironmentConfig;
use Tester\Assert;

require __DIR__ . '/../../../bootstrap.php';

test('no variables change nothing', function (): void {
	Assert::same([], EnvironmentConfig::fromVariables([]));
	Assert::same([], EnvironmentConfig::fromVariables([
		'KONSEL_SMTP_HOST' => '  ',
		'KONSEL_APP_URL' => '',
	]));
});

test('variables map to parameters, proxy, HSTS and error e-mail', function (): void {
	Assert::same([
		'parameters' => [
			'appUrl' => 'https://konsel.matyho.cz',
			'encryptionKey' => 'a2V5',
			'mailFrom' => 'noreply@konsel.matyho.cz',
			'smtp' => [
				'host' => 'smtp.example.cz',
				'username' => 'konsel',
				'password' => 'tajne',
				'port' => 587,
				'encryption' => 'tls',
			],
		],
		'http' => [
			'proxy' => ['10.0.0.0/8', '172.16.0.0/12', '127.0.0.1'],
			'headers' => [
				'Strict-Transport-Security' => 'max-age=31536000',
			],
		],
		'tracy' => [
			'email' => 'spravce@example.cz',
			'fromEmail' => 'noreply@konsel.matyho.cz',
		],
	], EnvironmentConfig::fromVariables([
		'KONSEL_APP_URL' => 'https://konsel.matyho.cz/',
		'KONSEL_ENCRYPTION_KEY' => 'a2V5',
		'KONSEL_MAIL_FROM' => 'noreply@konsel.matyho.cz',
		'KONSEL_SMTP_HOST' => 'smtp.example.cz',
		'KONSEL_SMTP_PORT' => '587',
		'KONSEL_SMTP_USERNAME' => 'konsel',
		'KONSEL_SMTP_PASSWORD' => 'tajne',
		'KONSEL_SMTP_ENCRYPTION' => 'TLS',
		'KONSEL_TRUSTED_PROXIES' => '10.0.0.0/8, 172.16.0.0/12,127.0.0.1',
		'KONSEL_ERROR_EMAIL' => 'spravce@example.cz',
	]));
});

test('encryption key falls back to generated file, plain http has no HSTS', function (): void {
	Assert::same([
		'parameters' => [
			'appUrl' => 'http://192.168.1.10:8090',
			'encryptionKey' => 'ze-souboru',
			'smtp' => [
				'encryption' => null,
			],
		],
	], EnvironmentConfig::fromVariables([
		'KONSEL_APP_URL' => 'http://192.168.1.10:8090',
		'KONSEL_SMTP_ENCRYPTION' => 'none',
	], "ze-souboru\n"));

	Assert::same([
		'parameters' => [
			'encryptionKey' => 'z-prostredi',
		],
	], EnvironmentConfig::fromVariables([
		'KONSEL_ENCRYPTION_KEY' => 'z-prostredi',
	], 'ze-souboru'));
});

test('invalid port and encryption are rejected', function (): void {
	Assert::exception(fn() => EnvironmentConfig::fromVariables([
		'KONSEL_SMTP_PORT' => 'abc',
	]), InvalidArgumentException::class, "KONSEL_SMTP_PORT must be a number, 'abc' given.");
	Assert::exception(fn() => EnvironmentConfig::fromVariables([
		'KONSEL_SMTP_ENCRYPTION' => 'starttls',
	]), InvalidArgumentException::class, "KONSEL_SMTP_ENCRYPTION must be tls, ssl or none, 'starttls' given.");
});
