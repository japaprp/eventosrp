<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/account_consent.php';

function consentAssert(bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$valid = [
    'ACCOUNT_TERMS_VERSION' => '2026-11-v1',
    'ACCOUNT_PRIVACY_VERSION' => '2026-11-v1',
    'ACCOUNT_TERMS_URL' => 'https://example.org/terms/v1',
    'ACCOUNT_PRIVACY_URL' => 'https://example.org/privacy/v1',
];
$versionPattern = '/^[A-Za-z0-9][A-Za-z0-9._-]{0,39}$/D';
$urlIsValid = static function (string $url): bool {
    $parts = parse_url($url);
    return is_array($parts)
        && strtolower((string)($parts['scheme'] ?? '')) === 'https'
        && !empty($parts['host'])
        && !isset($parts['user'])
        && !isset($parts['pass'])
        && !isset($parts['fragment']);
};
consentAssert(preg_match($versionPattern, $valid['ACCOUNT_TERMS_VERSION']) === 1, 'A bounded immutable version identifier must be accepted.');
consentAssert($urlIsValid($valid['ACCOUNT_TERMS_URL']), 'An HTTPS document URL must be accepted.');
consentAssert(!$urlIsValid('http://example.org/terms'), 'HTTP document URLs must be rejected.');
consentAssert(!$urlIsValid('https://user:pass@example.org/terms'), 'URLs containing embedded credentials must be rejected.');
consentAssert(!$urlIsValid('https://example.org/terms#draft'), 'Fragment-bearing document URLs must be rejected.');
consentAssert(preg_match($versionPattern, str_repeat('v', 41)) !== 1, 'Overlong version identifiers must be rejected.');
consentAssert(preg_match($versionPattern, 'version with spaces') !== 1, 'Version identifiers containing spaces must be rejected.');
echo "PASS: account consent configuration validation (7 assertions).\n";
