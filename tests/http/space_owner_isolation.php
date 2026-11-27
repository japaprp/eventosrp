<?php
declare(strict_types=1);

function failTest(string $message): never {
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}
function apiRequest(string $base, string $action, string $token, string $method = 'GET', ?array $body = null): array {
    $handle = curl_init($base . '/api.php?action=' . rawurlencode($action));
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Content-Type: application/json'],
    ]);
    if ($body !== null) curl_setopt($handle, CURLOPT_POSTFIELDS, json_encode($body, JSON_THROW_ON_ERROR));
    $response = curl_exec($handle);
    if ($response === false) {
        $error = curl_error($handle);
        curl_close($handle);
        failTest("HTTP request failed: {$error}");
    }
    $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
    curl_close($handle);
    return [$status, json_decode((string)$response, true), (string)$response];
}

$rulesPath = dirname(__DIR__, 2) . '/database.rules.json';
$rules = json_decode((string) file_get_contents($rulesPath), true, 512, JSON_THROW_ON_ERROR);
$chatRules = $rules['rules']['chats']['$reservationId']['messages'] ?? null;
if (!is_array($chatRules)
    || !str_contains((string)($chatRules['.read'] ?? ''), 'members')
    || !str_contains((string)($chatRules['$messageId']['.write'] ?? ''), '!data.exists()')
    || !str_contains((string)($chatRules['$messageId']['sender']['.validate'] ?? ''), 'auth.uid')
    || !str_contains((string)($chatRules['$messageId']['text']['.validate'] ?? ''), '1000')
    || ($chatRules['$messageId']['$other']['.validate'] ?? true) !== false) {
    failTest('Firebase chat rules must enforce member-only reads, append-only writes, authenticated senders, bounded text and a closed message schema.');
}

$base = rtrim(getenv('HTTP_TEST_BASE_URL') ?: 'http://127.0.0.1:8088', '/');
$ownerA = 'isolated-http-test-owner-a';
$ownerB = 'isolated-http-test-owner-b';
$ids = [];

$testTokens = [
    'isolated-http-test-owner-a',
    'isolated-http-test-owner-b',
    'isolated-http-test-admin',
];
foreach ($testTokens as $testToken) {
    [$requirementsStatus, $requirements, $requirementsRaw] = apiRequest($base, 'account-consent-requirements', $testToken);
    if ($requirementsStatus !== 200 || !is_array($requirements) || empty($requirements['terms_version']) || empty($requirements['privacy_version'])) {
        failTest('The isolated test adapter must expose deterministic consent requirements: HTTP ' . $requirementsStatus . ' ' . $requirementsRaw);
    }
    [$statusBefore, $statusBeforeData] = apiRequest($base, 'account-consent-status', $testToken);
    if ($statusBefore !== 200 || ($statusBeforeData['accepted'] ?? true) !== false) {
        failTest('A new isolated account must start without current consent.');
    }
    [$blockedStatus] = apiRequest($base, 'my-spaces', $testToken);
    if ($blockedStatus !== 403) failTest('Business routes must be blocked before account consent.');
    [$falseStatus] = apiRequest($base, 'account-consent', $testToken, 'POST', [
        'accepted' => false,
        'terms_version' => $requirements['terms_version'],
        'privacy_version' => $requirements['privacy_version'],
    ]);
    if ($falseStatus !== 422) failTest('A false consent flag must be rejected.');
    [$staleStatus] = apiRequest($base, 'account-consent', $testToken, 'POST', [
        'accepted' => true,
        'terms_version' => 'stale-version',
        'privacy_version' => $requirements['privacy_version'],
    ]);
    if ($staleStatus !== 409) failTest('A stale document version must be rejected.');
    [$consentStatus, $consent, $consentRaw] = apiRequest($base, 'account-consent', $testToken, 'POST', [
        'accepted' => true,
        'terms_version' => $requirements['terms_version'],
        'privacy_version' => $requirements['privacy_version'],
        'accepted_at' => '1900-01-01 00:00:00',
    ]);
    if ($consentStatus !== 200 || ($consent['accepted'] ?? false) !== true || empty($consent['accepted_at']) || str_starts_with($consent['accepted_at'], '1900-')) {
        failTest('The isolated test adapter must persist account consent using server time, never client time: HTTP ' . $consentStatus . ' ' . $consentRaw);
    }
    [$repeatStatus, $repeatConsent] = apiRequest($base, 'account-consent', $testToken, 'POST', [
        'accepted' => true,
        'terms_version' => $requirements['terms_version'],
        'privacy_version' => $requirements['privacy_version'],
    ]);
    if ($repeatStatus !== 200 || ($repeatConsent['accepted_at'] ?? null) !== $consent['accepted_at']) {
        failTest('Repeated consent acceptance must be idempotent and preserve the original server timestamp.');
    }
}

for ($i = 1; $i <= 3; $i++) {
    $payload = [
        'name' => 'Espaço isolado A' . $i,
        'city' => 'Ribeirão Preto',
        'neighborhood' => 'Centro',
        'address' => 'Rua de teste ' . $i . ', 100',
        'type' => 'Salão',
        'capacity' => 80 + $i,
        'description' => 'Registro sintético exclusivo do banco descartável de CI.',
        'rules' => 'Não representa um anúncio real.',
        'events' => ['Aniversário'],
        'amenities' => [],
        'daily_price' => '1200.00',
        'weekend_price' => null,
        'check_in_time' => '09:00',
        'check_out_time' => '18:00',
        'minimum_days' => 1,
        'cleanup_minutes' => 60,
        'photos' => ['https://example.test/owner-a-' . $i . '.jpg'],
        'active' => true,
        'owner_authorized' => true,
    ];
    [$status, $data, $raw] = apiRequest($base, 'save-space', $ownerA, 'POST', $payload);
    if ($status !== 201 || !is_array($data) || empty($data['id'])) {
        failTest("Owner A could not create space {$i}: HTTP {$status} {$raw}");
    }
    if (($data['active'] ?? true) !== false) {
        failTest('A new owner listing must remain unpublished until platform approval.');
    }
    $ids[] = (int)$data['id'];
}
if (count(array_unique($ids)) !== 3) failTest('Three spaces did not receive independent IDs.');

[$statusA, $spacesA, $rawA] = apiRequest($base, 'my-spaces', $ownerA);
if ($statusA !== 200 || !is_array($spacesA) || count($spacesA) !== 3) {
    failTest('Owner A should see exactly three spaces: HTTP ' . $statusA . ' ' . $rawA);
}
[$statusB, $spacesB, $rawB] = apiRequest($base, 'my-spaces', $ownerB);
if ($statusB !== 200 || !is_array($spacesB) || count($spacesB) !== 0) {
    failTest('Owner B must not see Owner A spaces: HTTP ' . $statusB . ' ' . $rawB);
}

$pdo = new PDO(
    'mysql:host=' . (getenv('DB_HOST') ?: '127.0.0.1') . ';port=' . (getenv('DB_PORT') ?: '3306') . ';dbname=' . (getenv('DB_NAME') ?: 'eventosrp_http_test') . ';charset=utf8mb4',
    getenv('DB_USER') ?: 'root',
    getenv('DB_PASSWORD') ?: 'root',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
);
$ownerQuery = $pdo->prepare('SELECT id FROM users WHERE firebase_uid=?');
$ownerQuery->execute(['eventosrp-http-test-owner-a']);
$ownerAId = (int)$ownerQuery->fetchColumn();
$ownerQuery->execute(['eventosrp-http-test-owner-b']);
$ownerBId = (int)$ownerQuery->fetchColumn();
if ($ownerAId < 1 || $ownerBId < 1 || $ownerAId === $ownerBId) failTest('Owner test accounts were not isolated in the users table.');

$ownerStatusQuery = $pdo->prepare('SELECT owner_status FROM users WHERE id=?');
$ownerStatusQuery->execute([$ownerAId]);
if ($ownerStatusQuery->fetchColumn() !== 'pending') failTest('Creating a listing must submit the owner account for approval.');

[$nonAdminStatus] = apiRequest($base, 'owner-requests', $ownerA);
if ($nonAdminStatus !== 403) failTest('A normal owner must not access the platform approval queue.');

[$adminQueueStatus, $adminQueue, $adminQueueRaw] = apiRequest($base, 'owner-requests', 'isolated-http-test-admin');
if ($adminQueueStatus !== 200 || !is_array($adminQueue) || !in_array('eventosrp-http-test-owner-a', array_column($adminQueue, 'firebase_uid'), true)) {
    failTest('The platform administrator must see pending owner applications: HTTP ' . $adminQueueStatus . ' ' . $adminQueueRaw);
}
[$approvalStatus, $approval, $approvalRaw] = apiRequest($base, 'review-owner', 'isolated-http-test-admin', 'POST', [
    'firebase_uid' => 'eventosrp-http-test-owner-a',
    'decision' => 'approved',
]);
if ($approvalStatus !== 200 || ($approval['owner_status'] ?? '') !== 'approved') {
    failTest('The platform administrator must be able to approve an owner: HTTP ' . $approvalStatus . ' ' . $approvalRaw);
}


$promote = $pdo->prepare('UPDATE spaces SET is_demo=1 WHERE id=? AND owner_id=?');
$promote->execute([$ids[0], $ownerAId]);

$unauthorized = [
    'id' => $ids[0],
    'name' => 'Tentativa de alteração por outro proprietário',
    'city' => 'Ribeirão Preto',
    'neighborhood' => 'Centro',
    'address' => 'Rua de teste 1, 100',
    'type' => 'Salão',
    'capacity' => 81,
    'description' => 'Tentativa de edição cruzada.',
    'rules' => '',
    'events' => ['Aniversário'],
    'amenities' => [],
    'daily_price' => '1200.00',
    'weekend_price' => null,
    'check_in_time' => '09:00',
    'check_out_time' => '18:00',
    'minimum_days' => 1,
    'cleanup_minutes' => 60,
    'photos' => ['https://example.test/owner-a-1.jpg'],
    'active' => true,
    'owner_authorized' => true,
];
[$forbiddenStatus] = apiRequest($base, 'save-space', $ownerB, 'POST', $unauthorized);
if ($forbiddenStatus !== 404) failTest('A different owner must receive HTTP 404 when editing another owner space; got ' . $forbiddenStatus);

$authorized = $unauthorized;
$authorized['name'] = 'Espaço isolado A1 atualizado';
[$updateStatus, $updated, $updateRaw] = apiRequest($base, 'save-space', $ownerA, 'POST', $authorized);
if ($updateStatus !== 201 || !is_array($updated) || (int)$updated['id'] !== $ids[0]) {
    failTest('Owner A could not update its own space: HTTP ' . $updateStatus . ' ' . $updateRaw);
}

foreach (array_slice($ids, 1) as $index => $spaceId) {
    $approvedSpace = $authorized;
    $approvedSpace['id'] = $spaceId;
    $approvedSpace['name'] = 'Espaço aprovado A' . ($index + 2);
    [$approvedStatus, $approvedResult, $approvedRaw] = apiRequest($base, 'save-space', $ownerA, 'POST', $approvedSpace);
    if ($approvedStatus !== 201 || !is_array($approvedResult) || ($approvedResult['active'] ?? false) !== true) {
        failTest('An approved owner must be able to publish its own space: HTTP ' . $approvedStatus . ' ' . $approvedRaw);
    }
}

$bookingIds = [];
foreach ([$ids[1], $ids[2]] as $spaceId) {
    $booking = [
        'space_id' => $spaceId,
        'check_in' => '2030-11-15T09:00',
        'check_out' => '2030-11-16T18:00',
        'plan' => 'daily',
        'contact_name' => 'Cliente de teste',
        'phone' => '16999999999',
        'guests' => 10,
        'message' => 'Reserva sintética em banco descartável para validar calendários independentes.',
        'terms_accepted' => true,
    ];
    [$bookingStatus, $bookingResponse, $bookingRaw] = apiRequest($base, 'reserve', 'isolated-http-test-token', 'POST', $booking);
    if ($bookingStatus !== 201 || !is_array($bookingResponse) || empty($bookingResponse['reservation_id']) || ($bookingResponse['status'] ?? '') !== 'requested' || isset($bookingResponse['checkout_url'])) {
        failTest('Same period should create a payment-free request in different spaces: HTTP ' . $bookingStatus . ' ' . $bookingRaw);
    }
    $bookingIds[] = (string)$bookingResponse['reservation_id'];
}
$bookingCheck = $pdo->prepare("SELECT COUNT(DISTINCT space_id) FROM reservations WHERE id IN (?,?) AND status='requested'");
$bookingCheck->execute($bookingIds);
if ((int)$bookingCheck->fetchColumn() !== 2) failTest('Reservations in different spaces were not persisted independently.');

[$confirmStatus, $confirmed, $confirmRaw] = apiRequest($base, 'review-reservation', $ownerA, 'POST', [
    'reservation_id' => $bookingIds[0],
    'decision' => 'confirmed',
]);
if ($confirmStatus !== 200 || ($confirmed['status'] ?? '') !== 'confirmed') {
    failTest('The owner must be able to confirm a requested reservation: HTTP ' . $confirmStatus . ' ' . $confirmRaw);
}
[$declineStatus, $declined, $declineRaw] = apiRequest($base, 'review-reservation', $ownerA, 'POST', [
    'reservation_id' => $bookingIds[1],
    'decision' => 'declined',
]);
if ($declineStatus !== 200 || ($declined['status'] ?? '') !== 'declined') {
    failTest('The owner must be able to decline a requested reservation: HTTP ' . $declineStatus . ' ' . $declineRaw);
}
[$crossOwnerReviewStatus] = apiRequest($base, 'review-reservation', $ownerB, 'POST', [
    'reservation_id' => $bookingIds[0],
    'decision' => 'declined',
]);
if ($crossOwnerReviewStatus !== 404) failTest('Another owner must not review a reservation for a different space.');

[$customerChatStatus, $customerChat] = apiRequest($base, 'chat-access', 'isolated-http-test-token', 'POST', ['reservation_id' => $bookingIds[0]]);
if ($customerChatStatus !== 200 || ($customerChat['authorized'] ?? false) !== true) {
    failTest('The booking customer must access their own chat; got HTTP ' . $customerChatStatus);
}
[$ownerChatStatus, $ownerChat] = apiRequest($base, 'chat-access', $ownerA, 'POST', ['reservation_id' => $bookingIds[0]]);
if ($ownerChatStatus !== 200 || ($ownerChat['authorized'] ?? false) !== true) {
    failTest('The space owner must access the chat for a reservation on their space; got HTTP ' . $ownerChatStatus);
}
[$otherOwnerChatStatus] = apiRequest($base, 'chat-access', $ownerB, 'POST', ['reservation_id' => $bookingIds[0]]);
if ($otherOwnerChatStatus !== 404) {
    failTest('An unrelated owner must not access another reservation chat; got HTTP ' . $otherOwnerChatStatus);
}

$q = $pdo->prepare('SELECT COUNT(*) FROM spaces WHERE owner_id=? AND is_demo=0');
$q->execute([$ownerAId]);
$realCount = (int)$q->fetchColumn();
$q = $pdo->prepare('SELECT COUNT(*) FROM spaces WHERE owner_id=?');
$q->execute([$ownerBId]);
$ownerBCount = (int)$q->fetchColumn();
$q = $pdo->prepare('SELECT owner_authorization_version,owner_authorization_snapshot,owner_authorized_at,is_demo FROM spaces WHERE id=?');
$q->execute([$ids[0]]);
$record = $q->fetch(PDO::FETCH_ASSOC);
if ($realCount !== 3 || $ownerBCount !== 0 || !$record
    || $record['owner_authorization_version'] !== 'space-owner-v1-2026-11-27'
    || !$record['owner_authorization_snapshot']
    || !$record['owner_authorized_at']
    || (int)$record['is_demo'] !== 0) {
    failTest('Owner isolation, authorization snapshot, or demo promotion failed.');
}

echo "PASS: one owner created three independent spaces and listed only their own records.\n";
echo "PASS: a second owner could not read or edit another owner's space.\n";
echo "PASS: authorized edit promoted the owner record out of demo state and retained the authorization snapshot.\n";
