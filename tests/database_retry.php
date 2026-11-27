<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/database_retry.php';

$cases = [
    [2002, true, 'connection refused is transient'],
    [2003, true, 'cannot connect is transient'],
    [2005, true, 'temporary DNS failure is transient'],
    [2006, true, 'server has gone away is transient'],
    [2013, true, 'lost connection is transient'],
    [1045, false, 'access denied is not retried'],
    [1064, false, 'SQL syntax errors are not connection retries'],
    [0, false, 'unknown driver errors fail closed'],
];

foreach ($cases as [$driverCode, $expected, $label]) {
    $error = new PDOException('synthetic test exception', $driverCode);
    $actual = isTransientDatabaseConnectionError($error);
    if ($actual !== $expected) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
}

echo "PASS: transient MySQL connection errors are retried; authentication and SQL errors fail immediately.\n";
