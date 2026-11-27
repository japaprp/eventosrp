<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/database_retry.php';

$cases = [
    ['HY000', 2002, true, 'connection refused is transient'],
    ['HY000', 2003, true, 'cannot connect is transient'],
    ['HY000', 2005, true, 'temporary DNS failure is transient'],
    ['HY000', 2006, true, 'server has gone away is transient'],
    ['08S01', 2013, true, 'lost connection is transient'],
    ['HY000', 1045, false, 'access denied is not retried'],
    ['42000', 1064, false, 'SQL syntax errors are not connection retries'],
    ['HY000', 0, false, 'unknown driver errors fail closed'],
];

foreach ($cases as [$state, $driverCode, $expected, $label]) {
    $error = new PDOException('synthetic test exception');
    $error->errorInfo = [$state, $driverCode, 'synthetic'];
    $actual = isTransientDatabaseConnectionError($error);
    if ($actual !== $expected) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
}

echo "PASS: transient MySQL connection errors are retried; authentication and SQL errors fail immediately.\n";
