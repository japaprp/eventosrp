<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
try {
    $result = database()->query('SELECT 1')->fetchColumn();
    if ((int) $result !== 1) throw new RuntimeException('MySQL readiness query failed.');
    respond(['status' => 'ready', 'database' => 'ready']);
} catch (Throwable $error) {
    error_log('Encontro readiness: ' . get_class($error));
    respond(['status' => 'degraded', 'database' => 'unavailable'], 503);
}
