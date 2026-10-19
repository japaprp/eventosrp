<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/marketplace_oauth.php';

function assertOAuthSame(mixed $expected, mixed $actual, string $message): void {
    if ($expected !== $actual) throw new RuntimeException($message);
}
function assertOAuthInvalid(callable $operation, string $message): void {
    try { $operation(); } catch (Throwable) { return; }
    throw new RuntimeException($message);
}

$master = 'test-only-key-with-at-least-thirty-two-characters';
$plain = 'TEST-ACCESS-TOKEN-not-a-real-credential';
$encrypted = marketplaceEncryptSecret($plain, $master);
assertOAuthSame($plain, marketplaceDecryptSecret($encrypted, $master), 'Criptografia precisa permitir round-trip.');
if ($encrypted === $plain || base64_decode($encrypted, true) === false) throw new RuntimeException('Token precisa estar protegido em repouso.');
assertOAuthInvalid(fn() => marketplaceDecryptSecret($encrypted, 'different-test-key-with-at-least-32-chars'), 'Chave incorreta precisa falhar.');
assertOAuthInvalid(fn() => marketplaceEncryptSecret($plain, 'short'), 'Chave curta precisa ser rejeitada.');
assertOAuthInvalid(fn() => marketplaceDecryptSecret('not-base64!', $master), 'Conteúdo inválido precisa ser rejeitado.');

$verifier = marketplaceBase64Url(random_bytes(48));
$challenge = marketplaceBase64Url(hash('sha256', $verifier, true));
if (strlen($verifier) < 43 || strlen($verifier) > 128 || !preg_match('/^[A-Za-z0-9_-]+$/', $challenge)) {
    throw new RuntimeException('PKCE deve gerar verifier e challenge URL-safe.');
}
echo "Criptografia de tokens e primitives PKCE passaram.\n";
