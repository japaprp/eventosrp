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
    public function get(string $name, mixed $default = null): mixed {
        return match ($name) {
            'sub' => 'eventosrp-http-test-user',
            'email' => 'http-test@example.invalid',
            default => $default,
        };
    }
}
final class EventosRpHttpTestToken {
    public function claims(): EventosRpHttpTestClaims { return new EventosRpHttpTestClaims(); }
}
final class EventosRpHttpTestAuth {
    public function verifyIdToken(string $token, bool $checkIfRevoked = false): EventosRpHttpTestToken {
        if ($token !== 'isolated-http-test-token') {
            throw new RuntimeException('Invalid isolated test token.');
        }
        return new EventosRpHttpTestToken();
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
