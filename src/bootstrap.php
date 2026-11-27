<?php
declare(strict_types=1);
require dirname(__DIR__) . '/vendor/autoload.php';
Dotenv\Dotenv::createImmutable(dirname(__DIR__))->safeLoad();

function env(string $key, string $default = ''): string {
    $value = $_ENV[$key] ?? getenv($key);
    return $value === false || $value === null || $value === '' ? $default : (string) $value;
}

require_once __DIR__ . '/database_retry.php';

function database(): PDO {
    static $pdo;
    if ($pdo instanceof PDO) return $pdo;

    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_TIMEOUT => 5,
        PDO::ATTR_PERSISTENT => false,
    ];
    $sslCa = env('DB_SSL_CA');
    if ($sslCa !== '') {
        if (!is_file($sslCa)) throw new RuntimeException('O arquivo CA configurado para MySQL não foi encontrado.');
        $options[PDO::MYSQL_ATTR_SSL_CA] = $sslCa;
        $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = true;
    }

    $dsn = 'mysql:host=' . env('DB_HOST', '127.0.0.1') . ';port=' . env('DB_PORT', '3306') . ';dbname=' . env('DB_NAME', 'encontro') . ';charset=utf8mb4';
    $maxAttempts = 3;
    for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
        try {
            $pdo = new PDO($dsn, env('DB_USER'), env('DB_PASSWORD'), $options);
            return $pdo;
        } catch (PDOException $error) {
            if ($attempt === $maxAttempts || !isTransientDatabaseConnectionError($error)) throw $error;
            error_log('Encontro MySQL transient connection failure; retry ' . $attempt . '/' . $maxAttempts . ' (SQLSTATE ' . (string) $error->getCode() . ').');
            usleep($attempt * 1000000);
        }
    }

    throw new RuntimeException('Não foi possível estabelecer conexão com o MySQL.');
}

function eventosrpHttpTestMode(): bool {
    if (!defined('EVENTOSRP_HTTP_TEST') || EVENTOSRP_HTTP_TEST !== true || PHP_SAPI !== 'cli-server' || env('EVENTOSRP_HTTP_TEST') !== '1') return false;
    return env('DB_NAME') === 'eventosrp_http_test'
        && in_array(env('DB_HOST', '127.0.0.1'), ['127.0.0.1', 'localhost', '::1'], true)
        && env('DB_PORT', '3306') === '3306'
        && env('APP_URL') === 'https://eventosrp-test.invalid';
}

function firebase(): mixed {
    if (eventosrpHttpTestMode()) return new EventosRpHttpTestFirebase();
    $credentialsJson = env('FIREBASE_CREDENTIALS_JSON');
    if ($credentialsJson !== '') {
        $credentials = json_decode($credentialsJson, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($credentials) || empty($credentials['project_id'])) throw new RuntimeException('FIREBASE_CREDENTIALS_JSON não contém uma conta de serviço válida.');
    } else {
        $credentials = env('FIREBASE_CREDENTIALS');
        if ($credentials === '') throw new RuntimeException('Configure FIREBASE_CREDENTIALS_JSON ou FIREBASE_CREDENTIALS.');
    }
    return (new Kreait\Firebase\Factory())->withServiceAccount($credentials)->withDatabaseUri(env('FIREBASE_DATABASE_URL'));
}

function respond(mixed $data, int $status = 200): never {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    exit;
}

function mp(string $path, ?array $payload = null): array {
    if (eventosrpHttpTestMode()) {
        if ($path !== '/checkout/preferences' || !is_array($payload) || empty($payload['external_reference'])) {
            throw new RuntimeException('Blocked non-checkout Mercado Pago call in isolated HTTP test.');
        }
        $id = 'http-test-pref-' . substr(hash('sha256', (string) $payload['external_reference']), 0, 20);
        return [
            'id' => $id,
            'sandbox_init_point' => 'https://sandbox.mercadopago.com.br/checkout/v1/redirect?pref_id=' . $id,
            'init_point' => 'https://www.mercadopago.com.br/checkout/v1/redirect?pref_id=' . $id,
        ];
    }

    $curl = curl_init('https://api.mercadopago.com' . $path);
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . env('MP_ACCESS_TOKEN'), 'Content-Type: application/json']]);
    if ($payload !== null) curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($payload, JSON_THROW_ON_ERROR)]);
    $result = curl_exec($curl);
    $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);
    if ($result === false || $status < 200 || $status >= 300) throw new RuntimeException('Falha no provedor de pagamento.');
    return json_decode($result, true, 512, JSON_THROW_ON_ERROR);
}
