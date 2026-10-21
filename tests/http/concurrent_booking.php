<?php
declare(strict_types=1);

function failTest(string $message): never {
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}
function request(string $url, string $method = 'GET', ?array $body = null): array {
    $handle = curl_init($url);
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer isolated-http-test-token', 'Content-Type: application/json'],
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
    return [$status, (string) $response];
}

$base = rtrim(getenv('HTTP_TEST_BASE_URL') ?: 'http://127.0.0.1:8088', '/');
$ready = false;
for ($attempt = 0; $attempt < 60; $attempt++) {
    [$status] = request($base . '/api.php?action=spaces');
    if ($status === 200) { $ready = true; break; }
    usleep(250000);
}
if (!$ready) failTest('API server did not become ready on the isolated local port.');

[$warmStatus, $warmBody] = request($base . '/api.php?action=reservations');
if ($warmStatus !== 200) failTest("Auth/DB warm-up returned HTTP {$warmStatus}: {$warmBody}");

$body = [
    'space_id' => 1,
    'check_in' => '2030-11-15T09:00',
    'check_out' => '2030-11-16T09:00',
    'plan' => 'daily',
    'contact_name' => 'Teste Concorrência HTTP',
    'phone' => '16999999999',
    'guests' => 10,
    'message' => 'Reserva descartável para teste de concorrência HTTP.',
    'terms_accepted' => true,
];
$url = $base . '/api.php?action=reserve';
$handles = [];
$multi = curl_multi_init();
for ($i = 0; $i < 2; $i++) {
    $h = curl_init($url);
    curl_setopt_array($h, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($body, JSON_THROW_ON_ERROR),
        CURLOPT_HTTPHEADER => ['Authorization: Bearer isolated-http-test-token', 'Content-Type: application/json'],
    ]);
    $handles[] = $h;
    curl_multi_add_handle($multi, $h);
}
$running = null;
do {
    $multiStatus = curl_multi_exec($multi, $running);
    if ($running > 0) curl_multi_select($multi, 0.2);
} while ($running > 0 && $multiStatus === CURLM_OK);
if ($multiStatus !== CURLM_OK) failTest('curl_multi failed while sending simultaneous reservation requests.');

$results = [];
foreach ($handles as $h) {
    $results[] = [(int) curl_getinfo($h, CURLINFO_HTTP_CODE), (string) curl_multi_getcontent($h)];
    curl_multi_remove_handle($multi, $h);
    curl_close($h);
}
curl_multi_close($multi);
sort($results);
$statuses = array_column($results, 0);
sort($statuses);
if ($statuses !== [201, 409]) {
    failTest('Expected exactly one HTTP 201 and one HTTP 409; got ' . json_encode($results, JSON_UNESCAPED_SLASHES));
}

$created = null;
foreach ($results as [$status, $response]) {
    $decoded = json_decode($response, true);
    if ($status === 201) {
        if (!is_array($decoded) || empty($decoded['reservation_id']) || empty($decoded['checkout_url'])) {
            failTest('The HTTP 201 response did not include the mocked checkout response.');
        }
        $created = (string) $decoded['reservation_id'];
    }
    if ($status === 409 && (!is_array($decoded) || empty($decoded['error']))) {
        failTest('The HTTP 409 response did not contain the expected conflict error.');
    }
}
if ($created === null) failTest('No successful reservation ID was returned.');

$pdo = new PDO(
    'mysql:host=' . (getenv('DB_HOST') ?: '127.0.0.1') . ';port=' . (getenv('DB_PORT') ?: '3306') . ';dbname=' . (getenv('DB_NAME') ?: 'eventosrp_http_test') . ';charset=utf8mb4',
    getenv('DB_USER') ?: 'root',
    getenv('DB_PASSWORD') ?: 'root',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
);
$all = (int) $pdo->query('SELECT COUNT(*) FROM reservations')->fetchColumn();
$active = (int) $pdo->query("SELECT COUNT(*) FROM reservations WHERE status IN ('creating','pending','approved')")->fetchColumn();
$failed = (int) $pdo->query("SELECT COUNT(*) FROM reservations WHERE status='failed'")->fetchColumn();
$events = (int) $pdo->query('SELECT COUNT(*) FROM reservation_payment_events')->fetchColumn();
$q = $pdo->prepare('SELECT status,preference_id,hold_expires_at,total,payment_status,payment_attention FROM reservations WHERE id=?');
$q->execute([$created]);
$row = $q->fetch(PDO::FETCH_ASSOC);
if ($all !== 1 || $active !== 1 || $failed !== 0 || $events !== 0) {
    failTest("Unexpected persisted rows: all={$all}, active={$active}, failed={$failed}, payment_events={$events}.");
}
if (!$row || $row['status'] !== 'pending' || !$row['preference_id'] || !$row['hold_expires_at'] || (float) $row['total'] <= 0 || $row['payment_status'] !== null || $row['payment_attention'] !== 'none') {
    failTest('Successful reservation is incomplete or remains in an unexpected state: ' . json_encode($row));
}
$uid = $pdo->query("SELECT COUNT(*) FROM users WHERE firebase_uid='eventosrp-http-test-user'")->fetchColumn();
if ((int) $uid !== 1) failTest('Expected exactly one isolated test user row.');

echo "PASS: real HTTP endpoint returned one 201 and one 409 for concurrent requests.\n";
echo "PASS: one pending reservation persisted; no duplicate, failed, or partial reservation rows.\n";
echo "PASS: Firebase and Mercado Pago were intercepted by test-only adapters; no external payment call was made.\n";
