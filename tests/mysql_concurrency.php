<?php
declare(strict_types=1);

/**
 * Integration test for the MySQL booking critical section used by public/api.php.
 * Runs only against a disposable CI database. Never point this test at production.
 */
$host = getenv('MYSQL_HOST') ?: '127.0.0.1';
$port = getenv('MYSQL_PORT') ?: '3306';
$db = getenv('MYSQL_DATABASE') ?: 'eventosrp_test';
$user = getenv('MYSQL_USER') ?: 'root';
$pass = getenv('MYSQL_PASSWORD') ?: 'root';

$connect = static function () use ($host, $port, $db, $user, $pass): PDO {
    return new PDO("mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
};

if (!function_exists('pcntl_fork') || !function_exists('pcntl_waitpid')) {
    fwrite(STDERR, "SKIP/FAIL: pcntl is required for independent concurrent PHP processes.\n");
    exit(2);
}

$pdo = $connect();
$pdo->exec('DROP TABLE IF EXISTS concurrency_test_reservations');
$pdo->exec('DROP TABLE IF EXISTS concurrency_test_spaces');
$pdo->exec("CREATE TABLE concurrency_test_spaces (
    id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    name VARCHAR(80) NOT NULL
) ENGINE=InnoDB");
$pdo->exec("CREATE TABLE concurrency_test_reservations (
    id CHAR(32) NOT NULL PRIMARY KEY,
    space_id BIGINT UNSIGNED NOT NULL,
    check_in DATETIME NOT NULL,
    blocked_until DATETIME NOT NULL,
    status ENUM('creating','pending','approved','failed','cancelled','expired') NOT NULL,
    INDEX availability (space_id, status, check_in, blocked_until),
    CONSTRAINT fk_concurrency_test_space FOREIGN KEY (space_id) REFERENCES concurrency_test_spaces(id)
) ENGINE=InnoDB");
$pdo->exec("INSERT INTO concurrency_test_spaces(id,name) VALUES (1,'Disposable test space')");
unset($pdo); // Never let forked workers inherit the parent's live PDO socket.

$rounds = 5;
$successesTotal = 0;
$conflictsTotal = 0;
$tempDir = sys_get_temp_dir() . '/eventosrp-mysql-concurrency-' . getmypid();
if (!mkdir($tempDir, 0700) && !is_dir($tempDir)) {
    throw new RuntimeException('Could not create synchronization directory.');
}

try {
    for ($round = 1; $round <= $rounds; $round++) {
        $pdo = $connect();
        $pdo->exec('DELETE FROM concurrency_test_reservations');
        unset($pdo); // Each process must use its own connection; parent reconnects after wait.

        $goFile = "{$tempDir}/go-{$round}";
        $readyFiles = ["{$tempDir}/ready-{$round}-0", "{$tempDir}/ready-{$round}-1"];
        $pids = [];

        for ($worker = 0; $worker < 2; $worker++) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                throw new RuntimeException('Could not fork concurrent worker.');
            }
            if ($pid === 0) {
                try {
                    $workerPdo = $connect();
                    file_put_contents($readyFiles[$worker], 'ready', LOCK_EX);
                    $deadline = microtime(true) + 10;
                    while (!is_file($goFile)) {
                        if (microtime(true) > $deadline) {
                            exit(21);
                        }
                        usleep(1000);
                    }

                    $workerPdo->beginTransaction();
                    // Same serialization strategy as the booking API: lock the parent space row.
                    $lock = $workerPdo->prepare('SELECT id FROM concurrency_test_spaces WHERE id=? FOR UPDATE');
                    $lock->execute([1]);
                    usleep(150000);

                    $check = $workerPdo->prepare("SELECT id FROM concurrency_test_reservations
                        WHERE space_id=? AND check_in < ? AND blocked_until > ?
                        AND status IN ('creating','pending','approved') LIMIT 1");
                    $check->execute([1, '2026-11-15 18:00:00', '2026-11-15 10:00:00']);
                    if ($check->fetchColumn()) {
                        $workerPdo->rollBack();
                        $workerPdo = null;
                        exit(10); // expected conflict / HTTP 409 equivalent
                    }

                    $id = bin2hex(random_bytes(16));
                    $insert = $workerPdo->prepare("INSERT INTO concurrency_test_reservations
                        (id,space_id,check_in,blocked_until,status) VALUES (?,?,?,?,'pending')");
                    $insert->execute([$id, 1, '2026-11-15 10:00:00', '2026-11-15 18:00:00']);
                    $workerPdo->commit();
                    $workerPdo = null;
                    exit(0); // exactly one worker should succeed
                } catch (Throwable $e) {
                    fwrite(STDERR, 'Worker error: ' . $e->getMessage() . "\n");
                    exit(22);
                }
            }
            $pids[$worker] = $pid;
        }

        $readyDeadline = microtime(true) + 10;
        while (!is_file($readyFiles[0]) || !is_file($readyFiles[1])) {
            if (microtime(true) > $readyDeadline) {
                throw new RuntimeException("Round {$round}: workers did not reach the barrier.");
            }
            usleep(1000);
        }
        touch($goFile);

        $codes = [];
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
            $codes[] = pcntl_wifexited($status) ? pcntl_wexitstatus($status) : 99;
        }
        sort($codes);
        if ($codes !== [0, 10]) {
            throw new RuntimeException("Round {$round}: expected one success and one conflict; got worker exit codes " . implode(',', $codes));
        }

        $pdo = $connect();
        $count = (int) $pdo->query("SELECT COUNT(*) FROM concurrency_test_reservations WHERE status IN ('creating','pending','approved')")->fetchColumn();
        unset($pdo);
        if ($count !== 1) {
            throw new RuntimeException("Round {$round}: expected exactly one active reservation; found {$count}.");
        }
        $successesTotal++;
        $conflictsTotal++;
        echo "Round {$round}: 1 reservation committed; 1 concurrent request rejected.\n";
        foreach (array_merge([$goFile], $readyFiles) as $file) {
            if (is_file($file)) unlink($file);
        }
    }

    echo "PASS: {$rounds} rounds; {$successesTotal} committed reservations and {$conflictsTotal} conflict rejections.\n";
} finally {
    // Cleanup uses a fresh connection because no connection should survive across fork().
    $cleanup = $connect();
    $cleanup->exec('DROP TABLE IF EXISTS concurrency_test_reservations');
    $cleanup->exec('DROP TABLE IF EXISTS concurrency_test_spaces');
    $cleanup = null;
    foreach (glob($tempDir . '/*') ?: [] as $file) {
        if (is_file($file)) unlink($file);
    }
    if (is_dir($tempDir)) rmdir($tempDir);
}
