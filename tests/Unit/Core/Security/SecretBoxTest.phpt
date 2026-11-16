<?php

declare(strict_types=1);

use App\Core\Security\SecretBox;
use Tester\Assert;

require __DIR__ . '/../../../bootstrap.php';

test('encrypted value round-trips and never contains the plaintext', function (): void {
	$box = new SecretBox(SecretBox::generateKey());
	$secret = 'sk-proj-abcdefghijklmnopqrstuvwxyz0123';

	$first = $box->encrypt($secret);
	$second = $box->encrypt($secret);

	Assert::true($box->isConfigured());
	Assert::notContains('abcdefghij', $first);
	Assert::notSame($first, $second, 'každé šifrování má vlastní nonce');
	Assert::same($secret, $box->decrypt($first));
	Assert::same($secret, $box->decrypt($second));
});

test('tampered data or a different key cannot be decrypted', function (): void {
	$box = new SecretBox(SecretBox::generateKey());
	$encrypted = $box->encrypt('tajemství');
	$tampered = substr($encrypted, 0, -4) . (str_ends_with($encrypted, 'AAAA') ? 'BBBB' : 'AAAA');

	Assert::exception(fn() => $box->decrypt($tampered), RuntimeException::class);
	Assert::exception(fn() => (new SecretBox(SecretBox::generateKey()))->decrypt($encrypted), RuntimeException::class);
	Assert::exception(fn() => $box->decrypt('nesmysl'), RuntimeException::class);
});

test('missing key disables encryption, invalid key is a configuration error', function (): void {
	$box = new SecretBox('');

	Assert::false($box->isConfigured());
	Assert::exception(fn() => $box->encrypt('x'), LogicException::class);
	Assert::exception(fn() => new SecretBox(base64_encode('krátký')), LogicException::class);
	Assert::exception(fn() => new SecretBox('není base64!'), LogicException::class);
});
