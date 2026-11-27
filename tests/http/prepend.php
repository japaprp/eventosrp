<?php
declare(strict_types=1);

// Loaded only by the dedicated local PHP development server in CI.
if (PHP_SAPI === 'cli-server' && getenv('EVENTOSRP_HTTP_TEST') === '1') {
    $databaseName = getenv('DB_NAME') ?: '';
    $databaseHost = getenv('DB_HOST') ?: '';
    $databasePort = getenv('DB_PORT') ?: '';
    $appUrl = getenv('APP_URL') ?: '';
    if (
        $databaseName !== 'eventosrp_http_test'
        || !in_array($databaseHost, ['127.0.0.1', 'localhost', '::1'], true)
        || $databasePort !== '3306'
        || $appUrl !== 'https://eventosrp-test.invalid'
    ) {
        http_response_code(503);
        exit('Isolated HTTP test mode requires the exact disposable database and test-only application URL.');
    }
    define('EVENTOSRP_HTTP_TEST', true);
}

final class EventosRpHttpTestClaims {
    public function __construct(private string $uid, private string $email) {}
    public function get(string $name, mixed $default = null): mixed {
        return match ($name) {
            'sub' => $this->uid,
            'email' => $this->email,
            default => $default,
        };
    }
}
final class EventosRpHttpTestToken {
    public function __construct(private string $uid, private string $email) {}
    public function claims(): EventosRpHttpTestClaims { return new EventosRpHttpTestClaims($this->uid, $this->email); }
}
final class EventosRpHttpTestAuth {
    public function verifyIdToken(string $token, bool $checkIfRevoked = false): EventosRpHttpTestToken {
        $users = [
            'isolated-http-test-token' => ['eventosrp-http-test-user', 'http-test@example.invalid'],
            'isolated-http-test-owner-a' => ['eventosrp-http-test-owner-a', 'owner-a@example.invalid'],
            'isolated-http-test-owner-b' => ['eventosrp-http-test-owner-b', 'owner-b@example.invalid'],
        ];
        if (!isset($users[$token])) throw new RuntimeException('Invalid isolated test token.');
        return new EventosRpHttpTestToken($users[$token][0], $users[$token][1]);
    }
}
final class EventosRpHttpTestReference {
    public function set(mixed $value): void {
        // Deliberately no external Firebase access.
    }
}
final class EventosRpHttpTestDatabase {
    public function getReference(string $path): EventosRpHttpTestReference {
        return new EventosRpHttpTestReference();
    }
}
final class EventosRpHttpTestFirebase {
    public function createAuth(): EventosRpHttpTestAuth { return new EventosRpHttpTestAuth(); }
    public function createDatabase(): EventosRpHttpTestDatabase { return new EventosRpHttpTestDatabase(); }
}
