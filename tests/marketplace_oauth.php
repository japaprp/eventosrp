<?php
declare(strict_types=1);

function env(string $key, string $default=''): string {
    $values = [
        'MP_OAUTH_CLIENT_ID'=>'test-client-id',
        'MP_OAUTH_CLIENT_SECRET'=>'test-client-secret',
        'MP_OAUTH_REDIRECT_URI'=>'https://example.test/api.php?action=mp-oauth-callback',
        'MP_TOKEN_ENCRYPTION_KEY'=>'test-only-key-with-at-least-thirty-two-characters',
    ];
    return $values[$key] ?? $default;
}
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

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE marketplace_oauth_states (
 state_hash TEXT PRIMARY KEY, owner_id INTEGER NOT NULL, code_verifier_encrypted TEXT NOT NULL,
 expires_at TEXT NOT NULL, used_at TEXT NULL
)');
$state = bin2hex(random_bytes(32));
$stateVerifier = marketplaceBase64Url(random_bytes(48));
$stateHash = hash('sha256', $state);
$expiry = (new DateTimeImmutable('+5 minutes'))->format('Y-m-d H:i:s');
$q = $pdo->prepare('INSERT INTO marketplace_oauth_states(state_hash,owner_id,code_verifier_encrypted,expires_at) VALUES (?,?,?,?)');
$q->execute([$stateHash,42,marketplaceEncryptSecret($stateVerifier,$master),$expiry]);
$consumed = marketplaceConsumeOAuthState($pdo,$state);
assertOAuthSame(42,$consumed['owner_id'],'State deve retornar o usuário dono');
assertOAuthSame($stateVerifier,$consumed['code_verifier'],'State deve recuperar o verifier');
assertOAuthInvalid(fn() => marketplaceConsumeOAuthState($pdo,$state),'State consumido não pode ser reutilizado');
assertOAuthInvalid(fn() => marketplaceConsumeOAuthState($pdo,str_repeat('0',64)),'State desconhecido deve falhar');
$expiredState = bin2hex(random_bytes(32));
$q->execute([hash('sha256',$expiredState),43,marketplaceEncryptSecret($stateVerifier,$master),(new DateTimeImmutable('-1 minute'))->format('Y-m-d H:i:s')]);
assertOAuthInvalid(fn() => marketplaceConsumeOAuthState($pdo,$expiredState),'State expirado deve falhar');

echo "Criptografia, PKCE, expiração e replay de OAuth passaram.\n";
