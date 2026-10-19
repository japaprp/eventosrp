<?php
declare(strict_types=1);

function marketplaceEncryptSecret(string $plainText, string $masterSecret): string {
    if (strlen($masterSecret) < 32) throw new RuntimeException('Chave de criptografia do Mercado Pago não configurada.');
    $key = hash('sha256', $masterSecret, true);
    $nonce = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt($plainText, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, 'eventosrp-marketplace-v1', 16);
    if ($cipher === false || strlen($tag) !== 16) throw new RuntimeException('Não foi possível proteger as credenciais.');
    return base64_encode($nonce . $tag . $cipher);
}

function marketplaceDecryptSecret(string $encoded, string $masterSecret): string {
    if (strlen($masterSecret) < 32) throw new RuntimeException('Chave de criptografia do Mercado Pago não configurada.');
    $raw = base64_decode($encoded, true);
    if ($raw === false || strlen($raw) < 29) throw new RuntimeException('Credencial criptografada inválida.');
    $key = hash('sha256', $masterSecret, true);
    $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16), 'eventosrp-marketplace-v1');
    if ($plain === false) throw new RuntimeException('Não foi possível descriptografar a credencial.');
    return $plain;
}

function marketplaceOAuthConfiguration(): array {
    $clientId = env('MP_OAUTH_CLIENT_ID');
    $clientSecret = env('MP_OAUTH_CLIENT_SECRET');
    $redirectUri = env('MP_OAUTH_REDIRECT_URI');
    $encryptionKey = env('MP_TOKEN_ENCRYPTION_KEY');
    $parts = $redirectUri !== '' ? parse_url($redirectUri) : false;
    if ($clientId === '' || $clientSecret === '' || strlen($encryptionKey) < 32
        || !is_array($parts) || ($parts['scheme'] ?? '') !== 'https'
        || empty($parts['host']) || isset($parts['fragment']) || isset($parts['user']) || isset($parts['pass'])) {
        throw new RuntimeException('Conexão de anfitrião com Mercado Pago ainda não configurada.');
    }
    return ['client_id'=>$clientId,'client_secret'=>$clientSecret,'redirect_uri'=>$redirectUri,'encryption_key'=>$encryptionKey];
}

function marketplaceBase64Url(string $bytes): string {
    return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
}

function marketplaceOAuthStart(PDO $pdo, int $ownerId): string {
    $config = marketplaceOAuthConfiguration();
    $q = $pdo->prepare('SELECT id FROM spaces WHERE owner_id=? AND is_demo=0 LIMIT 1');
    $q->execute([$ownerId]);
    if (!$q->fetchColumn()) throw new DomainException('Cadastre um espaço próprio antes de conectar a conta de recebimento.');

    $state = bin2hex(random_bytes(32));
    $verifier = marketplaceBase64Url(random_bytes(48));
    $challenge = marketplaceBase64Url(hash('sha256', $verifier, true));
    $stateHash = hash('sha256', $state);
    $now = new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo'));
    $expires = $now->modify('+10 minutes')->format('Y-m-d H:i:s');

    $q = $pdo->prepare('INSERT INTO marketplace_oauth_states(state_hash,owner_id,code_verifier_encrypted,expires_at) VALUES (?,?,?,?)');
    $q->execute([$stateHash,$ownerId,marketplaceEncryptSecret($verifier,$config['encryption_key']),$expires]);

    $params = [
        'client_id'=>$config['client_id'],
        'response_type'=>'code',
        'platform_id'=>'mp',
        'redirect_uri'=>$config['redirect_uri'],
        'state'=>$state,
        'code_challenge'=>$challenge,
        'code_challenge_method'=>'S256',
        'scope'=>'offline_access',
    ];
    return 'https://auth.mercadopago.com.br/authorization?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
}

/** Consumes a state exactly once before exchanging a short-lived authorization code. */
function marketplaceConsumeOAuthState(PDO $pdo, string $state): array {
    if (!preg_match('/^[a-f0-9]{64}$/', $state)) throw new InvalidArgumentException('Estado OAuth inválido.');
    $config = marketplaceOAuthConfiguration();
    $now = (new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo')))->format('Y-m-d H:i:s');
    $pdo->beginTransaction();
    try {
        $q = $pdo->prepare('SELECT state_hash,owner_id,code_verifier_encrypted,expires_at,used_at FROM marketplace_oauth_states WHERE state_hash=? FOR UPDATE');
        $q->execute([hash('sha256',$state)]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        if (!$row || $row['used_at'] !== null || $row['expires_at'] <= $now || empty($row['code_verifier_encrypted'])) {
            throw new InvalidArgumentException('Estado OAuth expirado ou já utilizado.');
        }
        $q = $pdo->prepare('UPDATE marketplace_oauth_states SET used_at=? WHERE state_hash=? AND used_at IS NULL');
        $q->execute([$now,$row['state_hash']]);
        if ($q->rowCount() !== 1) throw new InvalidArgumentException('Estado OAuth já utilizado.');
        $verifier = marketplaceDecryptSecret((string)$row['code_verifier_encrypted'],$config['encryption_key']);
        $pdo->commit();
        return ['owner_id'=>(int)$row['owner_id'],'code_verifier'=>$verifier,'config'=>$config];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function marketplaceOAuthPost(array $payload): array {
    $curl = curl_init('https://api.mercadopago.com/oauth/token');
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_TIMEOUT=>20,
        CURLOPT_CONNECTTIMEOUT=>8,
        CURLOPT_POST=>true,
        CURLOPT_POSTFIELDS=>http_build_query($payload, '', '&', PHP_QUERY_RFC3986),
        CURLOPT_HTTPHEADER=>['Accept: application/json','Content-Type: application/x-www-form-urlencoded'],
    ]);
    $response = curl_exec($curl);
    $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);
    if (!is_string($response) || $status < 200 || $status >= 300) throw new RuntimeException('O Mercado Pago não concluiu a autorização. Tente conectar novamente.');
    $data = json_decode($response, true);
    if (!is_array($data)) throw new RuntimeException('Resposta OAuth inválida.');
    return $data;
}

function marketplaceFetchSellerId(string $accessToken): string {
    $curl = curl_init('https://api.mercadopago.com/users/me');
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_TIMEOUT=>20,
        CURLOPT_CONNECTTIMEOUT=>8,
        CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$accessToken,'Accept: application/json'],
    ]);
    $response = curl_exec($curl);
    $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);
    if (!is_string($response) || $status < 200 || $status >= 300) throw new RuntimeException('Não foi possível confirmar a conta de vendedor autorizada.');
    $data = json_decode($response, true);
    $sellerId = is_array($data) ? (string)($data['id'] ?? '') : '';
    if ($sellerId === '' || !ctype_digit($sellerId)) throw new RuntimeException('Identidade da conta de vendedor inválida.');
    return $sellerId;
}

function marketplaceOAuthComplete(PDO $pdo, string $code, string $state): int {
    if ($code === '' || strlen($code) > 2048) throw new InvalidArgumentException('Código OAuth inválido.');
    $consumed = marketplaceConsumeOAuthState($pdo, $state);
    $config = $consumed['config'];
    $tokens = marketplaceOAuthPost([
        'client_id'=>$config['client_id'],
        'client_secret'=>$config['client_secret'],
        'grant_type'=>'authorization_code',
        'code'=>$code,
        'redirect_uri'=>$config['redirect_uri'],
        'code_verifier'=>$consumed['code_verifier'],
    ]);
    $accessToken = (string)($tokens['access_token'] ?? '');
    $refreshToken = (string)($tokens['refresh_token'] ?? '');
    $expiresIn = filter_var($tokens['expires_in'] ?? null, FILTER_VALIDATE_INT);
    if ($accessToken === '' || $refreshToken === '' || !$expiresIn || $expiresIn < 60) {
        throw new RuntimeException('O Mercado Pago não retornou credenciais renováveis para o anfitrião.');
    }
    $sellerId = marketplaceFetchSellerId($accessToken);
    $now = new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo'));
    $expiresAt = $now->modify('+'.$expiresIn.' seconds')->format('Y-m-d H:i:s');
    $q = $pdo->prepare("INSERT INTO marketplace_seller_accounts(owner_id,seller_user_id,access_token_encrypted,refresh_token_encrypted,token_expires_at,status,connected_at) VALUES (?,?,?,?,?,'connected',?) ON DUPLICATE KEY UPDATE seller_user_id=VALUES(seller_user_id),access_token_encrypted=VALUES(access_token_encrypted),refresh_token_encrypted=VALUES(refresh_token_encrypted),token_expires_at=VALUES(token_expires_at),status='connected',connected_at=VALUES(connected_at)");
    $q->execute([
        $consumed['owner_id'],$sellerId,
        marketplaceEncryptSecret($accessToken,$config['encryption_key']),
        marketplaceEncryptSecret($refreshToken,$config['encryption_key']),
        $expiresAt,$now->format('Y-m-d H:i:s'),
    ]);
    return $consumed['owner_id'];
}

function marketplaceSellerAccountSummary(PDO $pdo, int $ownerId): array {
    $q = $pdo->prepare('SELECT seller_user_id,status,token_expires_at,connected_at FROM marketplace_seller_accounts WHERE owner_id=?');
    $q->execute([$ownerId]);
    $row = $q->fetch(PDO::FETCH_ASSOC);
    if (!$row) return ['status'=>'not_connected','seller_user_id'=>null,'token_expires_at'=>null,'connected_at'=>null];
    return ['status'=>(string)$row['status'],'seller_user_id'=>$row['seller_user_id'],'token_expires_at'=>$row['token_expires_at'],'connected_at'=>$row['connected_at']];
}

/** Serialize refresh-token rotation so concurrent requests cannot overwrite a newer token. */
function marketplaceSellerAccessToken(PDO $pdo, int $ownerId): string {
    $config = marketplaceOAuthConfiguration();
    $pdo->beginTransaction();
    try {
        $q = $pdo->prepare('SELECT * FROM marketplace_seller_accounts WHERE owner_id=? FOR UPDATE');
        $q->execute([$ownerId]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        if (!$row || $row['status'] !== 'connected' || empty($row['refresh_token_encrypted'])) {
            throw new RuntimeException('O anfitrião precisa reconectar sua conta Mercado Pago.');
        }
        $now = new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo'));
        $expires = !empty($row['token_expires_at']) ? new DateTimeImmutable((string)$row['token_expires_at'], new DateTimeZone('America/Sao_Paulo')) : $now;
        if ($expires > $now->modify('+5 minutes') && !empty($row['access_token_encrypted'])) {
            $access = marketplaceDecryptSecret((string)$row['access_token_encrypted'], $config['encryption_key']);
            $pdo->commit();
            return $access;
        }
        $refresh = marketplaceDecryptSecret((string)$row['refresh_token_encrypted'], $config['encryption_key']);
        try {
            $tokens = marketplaceOAuthPost([
                'client_id'=>$config['client_id'],
                'client_secret'=>$config['client_secret'],
                'grant_type'=>'refresh_token',
                'refresh_token'=>$refresh,
            ]);
            $access = (string)($tokens['access_token'] ?? '');
            $newRefresh = (string)($tokens['refresh_token'] ?? '');
            $expiresIn = filter_var($tokens['expires_in'] ?? null, FILTER_VALIDATE_INT);
            if ($access === '' || $newRefresh === '' || !$expiresIn || $expiresIn < 60) {
                throw new RuntimeException('Renovação OAuth incompleta.');
            }
            $expiresAt = $now->modify('+'.$expiresIn.' seconds')->format('Y-m-d H:i:s');
            $q = $pdo->prepare("UPDATE marketplace_seller_accounts SET access_token_encrypted=?,refresh_token_encrypted=?,token_expires_at=?,status='connected' WHERE owner_id=?");
            $q->execute([
                marketplaceEncryptSecret($access,$config['encryption_key']),
                marketplaceEncryptSecret($newRefresh,$config['encryption_key']),
                $expiresAt,$ownerId,
            ]);
            $pdo->commit();
            return $access;
        } catch (Throwable $e) {
            $q = $pdo->prepare("UPDATE marketplace_seller_accounts SET status='reauthorization_required' WHERE owner_id=?");
            $q->execute([$ownerId]);
            $pdo->commit();
            throw new RuntimeException('A conexão do anfitrião expirou. Reconecte a conta Mercado Pago.');
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function marketplaceDisconnectSellerAccount(PDO $pdo, int $ownerId): void {
    $now = (new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo')))->format('Y-m-d H:i:s');
    $pdo->beginTransaction();
    try {
        $q = $pdo->prepare("UPDATE marketplace_seller_accounts SET seller_user_id=NULL,access_token_encrypted=NULL,refresh_token_encrypted=NULL,token_expires_at=NULL,status='disconnected' WHERE owner_id=?");
        $q->execute([$ownerId]);
        $q = $pdo->prepare('UPDATE marketplace_oauth_states SET used_at=? WHERE owner_id=? AND used_at IS NULL');
        $q->execute([$now,$ownerId]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
