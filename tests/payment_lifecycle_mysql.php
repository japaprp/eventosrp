<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/payment_lifecycle.php';

$dsn = sprintf(
    'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
    getenv('MYSQL_HOST') ?: '127.0.0.1',
    getenv('MYSQL_PORT') ?: '3306',
    getenv('MYSQL_DATABASE') ?: 'eventosrp_test'
);
$pdo = new PDO($dsn, getenv('MYSQL_USER') ?: 'root', getenv('MYSQL_PASSWORD') ?: 'root', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);

$pdo->exec('CREATE TABLE spaces (id BIGINT UNSIGNED PRIMARY KEY) ENGINE=InnoDB');
$pdo->exec("CREATE TABLE reservations (
    id CHAR(32) PRIMARY KEY,
    space_id BIGINT UNSIGNED NOT NULL,
    status VARCHAR(20) NOT NULL,
    payment_id VARCHAR(100) NULL,
    payment_status VARCHAR(32) NULL,
    payment_status_detail VARCHAR(100) NULL,
    payment_updated_at DATETIME NULL,
    payment_attention VARCHAR(32) NOT NULL DEFAULT 'none',
    total DECIMAL(10,2) NOT NULL
) ENGINE=InnoDB");
$pdo->exec("CREATE TABLE reservation_payment_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    reservation_id CHAR(32) NOT NULL,
    payment_id VARCHAR(100) NOT NULL UNIQUE,
    provider_status VARCHAR(32) NOT NULL,
    status_detail VARCHAR(100) NULL,
    amount DECIMAL(10,2) NOT NULL,
    currency VARCHAR(3) NOT NULL,
    event_count INT UNSIGNED NOT NULL DEFAULT 1,
    first_seen_at DATETIME NOT NULL,
    last_seen_at DATETIME NOT NULL
) ENGINE=InnoDB");

$reservationId = str_repeat('a', 32);
$pdo->exec("INSERT INTO spaces(id) VALUES (1)");
$q = $pdo->prepare("INSERT INTO reservations(id,space_id,status,payment_id,payment_status,payment_attention,total) VALUES (?,1,'approved','pay-1','approved','none',100.00)");
$q->execute([$reservationId]);

function assertDatabaseState(PDO $pdo, string $reservationId, string $paymentStatus, string $attention, string $auditStatus, int $eventCount, string $label): void {
    $q = $pdo->prepare('SELECT status,payment_status,payment_attention FROM reservations WHERE id=?');
    $q->execute([$reservationId]);
    $reservation = $q->fetch();
    $q = $pdo->prepare('SELECT provider_status,event_count FROM reservation_payment_events WHERE payment_id=?');
    $q->execute(['pay-1']);
    $audit = $q->fetch();
    if (!$reservation || !$audit
        || $reservation['status'] !== 'approved'
        || $reservation['payment_status'] !== $paymentStatus
        || $reservation['payment_attention'] !== $attention
        || $audit['provider_status'] !== $auditStatus
        || (int)$audit['event_count'] !== $eventCount) {
        throw new RuntimeException($label . ': estado de reserva/auditoria divergente: ' . json_encode(['reservation'=>$reservation,'audit'=>$audit]));
    }
}

$sequence = [
    ['approved', 'approved', 'none', 'approved', 1],
    ['pending', 'approved', 'none', 'approved', 2],
    ['rejected', 'approved', 'none', 'approved', 3],
    ['refunded', 'refunded', 'review_required', 'refunded', 4],
    ['charged_back', 'charged_back', 'chargeback', 'charged_back', 5],
];
foreach ($sequence as [$incomingStatus, $expectedReservationPayment, $attention, $expectedAuditStatus, $eventCount]) {
    paymentLifecycleApplyVerifiedPayment($pdo, $reservationId, [
        'id' => 'pay-1',
        'external_reference' => $reservationId,
        'status' => $incomingStatus,
        'status_detail' => 'test_' . $incomingStatus,
        'transaction_amount' => '100.00',
        'currency_id' => 'BRL',
    ]);
    assertDatabaseState($pdo, $reservationId, $expectedReservationPayment, $attention, $expectedAuditStatus, $eventCount, $incomingStatus);
}
echo "MySQL lifecycle integration passed: approved -> pending -> rejected -> refunded -> charged_back; reservation and audit verified after each event.\n";
