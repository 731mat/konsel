<?php

declare(strict_types=1);

namespace App\Core\Security;

/**
 * Symetrické šifrování tajných údajů v DB (API klíče) přes libsodium secretbox.
 * Klíč aplikace je parametr `encryptionKey` v config/local.neon (base64, 32 bajtů) – vygeneruje `app:encryption-key`.
 */
final readonly class SecretBox
{
	private const string Version = 'v1:';

	/**
	 * @var non-empty-string|null
	 */
	private ?string $key;

	public function __construct(string $encryptionKey)
	{
		if ($encryptionKey === '') {
			$this->key = null;

			return;
		}

		$key = base64_decode($encryptionKey, true);
		if ($key === false || $key === '' || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
			throw new \LogicException('Parameter encryptionKey must be base64 encoded 32 bytes (run bin/console app:encryption-key).');
		}

		$this->key = $key;
	}

	public static function generateKey(): string
	{
		return base64_encode(sodium_crypto_secretbox_keygen());
	}

	public function isConfigured(): bool
	{
		return $this->key !== null;
	}

	/**
	 * @throws \LogicException šifrovací klíč aplikace není nastavený
	 */
	public function encrypt(string $plaintext): string
	{
		$nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

		return self::Version . base64_encode($nonce . sodium_crypto_secretbox($plaintext, $nonce, $this->requireKey()));
	}

	/**
	 * @throws \RuntimeException data jsou poškozená nebo zašifrovaná jiným klíčem
	 * @throws \LogicException šifrovací klíč aplikace není nastavený
	 */
	public function decrypt(string $ciphertext): string
	{
		$raw = str_starts_with($ciphertext, self::Version) ? base64_decode(substr($ciphertext, strlen(self::Version)), true) : false;
		if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
			throw new \RuntimeException('Encrypted value has invalid format.');
		}

		$plaintext = sodium_crypto_secretbox_open(
			substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
			substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
			$this->requireKey(),
		);
		if ($plaintext === false) {
			throw new \RuntimeException('Encrypted value cannot be decrypted (wrong key or tampered data).');
		}

		return $plaintext;
	}

	/**
	 * @return non-empty-string
	 */
	private function requireKey(): string
	{
		if ($this->key === null) {
			throw new \LogicException('Encryption key is not configured (parameter encryptionKey in config/local.neon).');
		}

		return $this->key;
	}
}
