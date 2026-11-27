<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/account_consent.php';

function consentAssert(bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$valid = accountConsentBuildRequirements(
    '2026-11-v1',
    '2026-11-v1',
    'https://example.org/terms/v1',
    'https://example.org/privacy/v1'
);
consentAssert(is_array($valid), 'A valid pair of versions and HTTPS URLs must be accepted.');
consentAssert($valid['terms_version'] === '2026-11-v1' && $valid['privacy_version'] === '2026-11-v1', 'The configured document versions must be preserved exactly.');
consentAssert(accountConsentBuildRequirements('', 'v1', 'https://example.org/t', 'https://example.org/p') === null, 'Missing document versions must fail closed.');
consentAssert(accountConsentBuildRequirements(str_repeat('v', 41), 'v1', 'https://example.org/t', 'https://example.org/p') === null, 'Overlong versions must be rejected.');
consentAssert(accountConsentBuildRequirements('version with spaces', 'v1', 'https://example.org/t', 'https://example.org/p') === null, 'Versions containing spaces must be rejected.');
consentAssert(accountConsentBuildRequirements('v1', 'v1', 'http://example.org/terms', 'https://example.org/privacy') === null, 'HTTP document URLs must be rejected.');
consentAssert(accountConsentBuildRequirements('v1', 'v1', 'https://user:pass@example.org/terms', 'https://example.org/privacy') === null, 'URLs containing embedded credentials must be rejected.');
consentAssert(accountConsentBuildRequirements('v1', 'v1', 'https://example.org/terms#draft', 'https://example.org/privacy') === null, 'Fragment-bearing document URLs must be rejected.');
consentAssert(accountConsentBuildRequirements('v1', 'v1', 'not a URL', 'https://example.org/privacy') === null, 'Malformed document URLs must be rejected.');
echo "PASS: account consent configuration validation (9 assertions).\n";
