<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/payment_lifecycle.php';

$dsn = 'mysql:host=127.0.0.1;port=3306;dbname=eventosrp_lock_test;charset=utf8mb4';
$options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC];
$webhook = new PDO($dsn, 'root', 'root', $options);
$reconciler = new PDO($dsn, 'root', 'root', $options);

$webhook->exec('DROP TABLE IF EXISTS reservations');
$webhook->exec('DROP TABLE IF EXISTS spaces');
$webhook->exec('CREATE TABLE spaces (id BIGINT UNSIGNED PRIMARY KEY) ENGINE=InnoDB');
$webhook->exec("CREATE TABLE reservations (
    id CHAR(32) PRIMARY KEY,
    space_id BIGINT UNSIGNED NOT NULL,
    status VARCHAR(20) NOT NULL,
    payment_attention VARCHAR(32) NOT NULL DEFAULT 'none',
    hold_expires_at DATETIME NULL
) ENGINE=InnoDB");

$reservationId = str_repeat('c', 32);
$webhook->exec('INSERT INTO spaces(id) VALUES (1)');
$insert = $webhook->prepare("INSERT INTO reservations(id, space_id, status, payment_attention, hold_expires_at) VALUES (?, 1, 'pending', 'none', '2026-10-19 11:00:00')");
$insert->execute([$reservationId]);

// Model the webhook having acquired the space lock and applying an approval.
$webhook->beginTransaction();
$lock = $webhook->query('SELECT id FROM spaces WHERE id = 1 FOR UPDATE');
$lock->fetch();

$reconciler->exec('SET SESSION innodb_lock_wait_timeout = 1');
$now = new DateTimeImmutable('2026-10-19T12:00:00-03:00');
$timedOut = false;
try {
    paymentLifecycleExpirePendingReservation($reconciler, $reservationId, $now);
} catch (PDOException $e) {
    if (!in_array((string)$e->getCode(), ['HY000', '40001'], true)) throw $e;
    $timedOut = true;
}
if (!$timedOut) throw new RuntimeException('Reconciliation did not wait for the webhook-held space lock.');

$approve = $webhook->prepare("UPDATE reservations SET status = 'approved', payment_attention = 'none' WHERE id = ?");
$approve->execute([$reservationId]);
$webhook->commit();

// After the webhook commits, reconciliation must re-read and preserve approval.
$expired = paymentLifecycleExpirePendingReservation($reconciler, $reservationId, $now);
$q = $reconciler->prepare('SELECT status FROM reservations WHERE id = ?');
$q->execute([$reservationId]);
$status = $q->fetchColumn();
if ($expired || $status !== 'approved') {
    throw new RuntimeException('Expired-hold reconciliation overwrote a concurrent approved reservation: ' . (string)$status);
}

echo "MySQL webhook/reconciliation race passed: webhook approval wins and expiry preserves approved state.\n";
