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
$pdo->exec('CREATE TABLE spaces (id INTEGER PRIMARY KEY, owner_id INTEGER, is_demo INTEGER)');
$pdo->exec('INSERT INTO spaces(id,owner_id,is_demo) VALUES (1,42,0)');
$pdo->exec('CREATE TABLE marketplace_oauth_states (
 state_hash TEXT PRIMARY KEY, owner_id INTEGER NOT NULL, code_verifier_encrypted TEXT NOT NULL,
 expires_at TEXT NOT NULL, used_at TEXT NULL
)');
$config = marketplaceOAuthConfiguration();
assertOAuthSame(true,$config['test_token'],'OAuth precisa iniciar em modo sandbox por padrão');
$authUrl = marketplaceOAuthStart($pdo,42);
$parts = parse_url($authUrl);
parse_str($parts['query'] ?? '', $query);
assertOAuthSame('auth.mercadopago.com.br',$parts['host'] ?? null,'Host de autorização precisa ser Mercado Pago');
assertOAuthSame('S256',$query['code_challenge_method'] ?? null,'OAuth precisa usar PKCE S256');
assertOAuthSame('offline_access',$query['scope'] ?? null,'OAuth precisa solicitar renovação offline');
$state = (string)($query['state'] ?? '');
if (!preg_match('/^[a-f0-9]{64}$/',$state) || empty($query['code_challenge'])) throw new RuntimeException('URL OAuth precisa conter state e challenge.');
$consumed = marketplaceConsumeOAuthState($pdo,$state);
assertOAuthSame(42,$consumed['owner_id'],'State deve retornar o usuário dono');
if (strlen($consumed['code_verifier']) < 43) throw new RuntimeException('Verifier PKCE não foi recuperado.');
assertOAuthInvalid(fn() => marketplaceConsumeOAuthState($pdo,$state),'State consumido não pode ser reutilizado');
assertOAuthInvalid(fn() => marketplaceConsumeOAuthState($pdo,str_repeat('0',64)),'State desconhecido deve falhar');
$expiredState = bin2hex(random_bytes(32));
$stateVerifier = marketplaceBase64Url(random_bytes(48));
$q = $pdo->prepare('INSERT INTO marketplace_oauth_states(state_hash,owner_id,code_verifier_encrypted,expires_at) VALUES (?,?,?,?)');
$q->execute([hash('sha256',$expiredState),43,marketplaceEncryptSecret($stateVerifier,$master),(new DateTimeImmutable('-1 minute', new DateTimeZone('America/Sao_Paulo')))->format('Y-m-d H:i:s')]);
assertOAuthInvalid(fn() => marketplaceConsumeOAuthState($pdo,$expiredState),'State expirado deve falhar');

// Seller identity must be unique across hosts; a conflict must not overwrite the original host row.
$pdo->exec("CREATE TABLE marketplace_seller_accounts (
 id INTEGER PRIMARY KEY AUTOINCREMENT, owner_id INTEGER NOT NULL UNIQUE,
 seller_user_id TEXT UNIQUE, access_token_encrypted TEXT, refresh_token_encrypted TEXT,
 token_expires_at TEXT, status TEXT NOT NULL, connected_at TEXT
)");
marketplaceStoreSellerAccount($pdo,42,'123456','access-token-host-42','refresh-token-host-42','2026-10-20 12:00:00','2026-10-19 12:00:00');
assertOAuthInvalid(
    fn() => marketplaceStoreSellerAccount($pdo,43,'123456','attacker-access','attacker-refresh','2026-10-20 12:00:00','2026-10-19 12:00:00'),
    'Uma conta de vendedor não pode ser vinculada a dois anfitriões'
);
$q = $pdo->prepare('SELECT owner_id,access_token_encrypted,refresh_token_encrypted FROM marketplace_seller_accounts WHERE seller_user_id=?');
$q->execute(['123456']);
$sellerRow = $q->fetch(PDO::FETCH_ASSOC);
assertOAuthSame(42,(int)$sellerRow['owner_id'],'A conexão original deve continuar no anfitrião correto');
assertOAuthSame('access-token-host-42',$sellerRow['access_token_encrypted'],'Conflito não pode substituir o token do anfitrião original');
assertOAuthSame('refresh-token-host-42',$sellerRow['refresh_token_encrypted'],'Conflito não pode substituir o refresh token original');
assertOAuthSame(1,(int)$pdo->query('SELECT COUNT(*) FROM marketplace_seller_accounts')->fetchColumn(),'Conflito não deve criar uma segunda conta');

echo "Criptografia, PKCE, expiração, replay e isolamento de contas OAuth passaram.\n";
