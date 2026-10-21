<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/payment_lifecycle.php';

$dsn = 'mysql:host=127.0.0.1;port=3306;dbname=eventosrp_lock_test;charset=utf8mb4';
$pdoA = new PDO($dsn, 'root', 'root', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdoB = new PDO($dsn, 'root', 'root', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$reservationId = str_repeat('b', 32);
$lockName = 'eventosrp_reconcile_' . substr(hash('sha256', $reservationId), 0, 40);

$held = $pdoB->prepare('SELECT GET_LOCK(?, 0)');
$held->execute([$lockName]);
if ((int)$held->fetchColumn() !== 1) throw new RuntimeException('Could not acquire test lock from second connection.');

$called = false;
$acquired = paymentLifecycleWithAdvisoryLock($pdoA, $reservationId, function () use (&$called): void { $called = true; });
if ($acquired || $called) throw new RuntimeException('Concurrent reconciler did not skip while another connection held the lock.');

$release = $pdoB->prepare('SELECT RELEASE_LOCK(?)');
$release->execute([$lockName]);

$called = false;
$acquired = paymentLifecycleWithAdvisoryLock($pdoA, $reservationId, function () use (&$called): void { $called = true; });
if (!$acquired || !$called) throw new RuntimeException('Reconciler did not run after the competing lock was released.');

$secondAcquire = $pdoB->prepare('SELECT GET_LOCK(?, 0)');
$secondAcquire->execute([$lockName]);
if ((int)$secondAcquire->fetchColumn() !== 1) throw new RuntimeException('Reconciler failed to release its lock after execution.');
$release->execute([$lockName]);

echo "MySQL reconciliation lock integration passed: overlapping worker skipped, then lock released and retried.\n";
