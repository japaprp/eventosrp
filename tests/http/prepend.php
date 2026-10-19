<?php
declare(strict_types=1);

// Loaded only by the dedicated local PHP development server in CI.
if (PHP_SAPI === 'cli-server' && getenv('EVENTOSRP_HTTP_TEST') === '1') {
    $databaseName = getenv('DB_NAME') ?: '';
    if (!str_ends_with($databaseName, '_test')) {
        http_response_code(503);
        exit('Isolated HTTP test mode requires a database ending in _test.');
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
