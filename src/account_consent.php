<?php
declare(strict_types=1);

/**
 * Resolve the only acceptable account-consent versions and immutable HTTPS URLs.
 * Test-only values are available solely inside the repository's isolated HTTP harness.
 */
function accountConsentBuildRequirements(string $termsVersion, string $privacyVersion, string $termsUrl, string $privacyUrl): ?array {
    $termsVersion = trim($termsVersion);
    $privacyVersion = trim($privacyVersion);
    $termsUrl = trim($termsUrl);
    $privacyUrl = trim($privacyUrl);

    $validVersion = static fn(string $value): bool =>
        preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,39}$/D', $value) === 1;
    $validUrl = static function (string $value): bool {
        if ($value === '' || strlen($value) > 2048 || filter_var($value, FILTER_VALIDATE_URL) === false) return false;
        $parts = parse_url($value);
        return is_array($parts)
            && strtolower((string)($parts['scheme'] ?? '')) === 'https'
            && !empty($parts['host'])
            && !isset($parts['user'])
            && !isset($parts['pass'])
            && !isset($parts['fragment']);
    };

    if (!$validVersion($termsVersion) || !$validVersion($privacyVersion)
        || !$validUrl($termsUrl) || !$validUrl($privacyUrl)) {
        return null;
    }

    return [
        'terms_version' => $termsVersion,
        'privacy_version' => $privacyVersion,
        'terms_url' => $termsUrl,
        'privacy_url' => $privacyUrl,
    ];
}

function accountConsentRequirements(): ?array {
    if (function_exists('eventosrpHttpTestMode') && eventosrpHttpTestMode()) {
        return accountConsentBuildRequirements(
            'test-terms-v1',
            'test-privacy-v1',
            'https://eventosrp-test.invalid/terms-v1',
            'https://eventosrp-test.invalid/privacy-v1'
        );
    }

    return accountConsentBuildRequirements(
        env('ACCOUNT_TERMS_VERSION'),
        env('ACCOUNT_PRIVACY_VERSION'),
        env('ACCOUNT_TERMS_URL'),
        env('ACCOUNT_PRIVACY_URL')
    );
}

function accountConsentIsCurrent(PDO $pdo, int $userId, array $requirements): bool {
    $query = $pdo->prepare(
        'SELECT 1 FROM account_consents
         WHERE user_id = ? AND terms_version = ? AND privacy_version = ?
         LIMIT 1'
    );
    $query->execute([$userId, $requirements['terms_version'], $requirements['privacy_version']]);
    return (bool)$query->fetchColumn();
}

function accountConsentAccept(PDO $pdo, int $userId, array $requirements): string {
    $query = $pdo->prepare(
        'INSERT IGNORE INTO account_consents (user_id, terms_version, privacy_version, accepted_at)
         VALUES (?, ?, ?, CURRENT_TIMESTAMP)'
    );
    $query->execute([$userId, $requirements['terms_version'], $requirements['privacy_version']]);

    $query = $pdo->prepare(
        'SELECT accepted_at FROM account_consents
         WHERE user_id = ? AND terms_version = ? AND privacy_version = ?
         LIMIT 1'
    );
    $query->execute([$userId, $requirements['terms_version'], $requirements['privacy_version']]);
    $acceptedAt = $query->fetchColumn();
    if (!is_string($acceptedAt) || $acceptedAt === '') {
        throw new RuntimeException('Não foi possível confirmar o registro do consentimento.');
    }
    return $acceptedAt;
}
