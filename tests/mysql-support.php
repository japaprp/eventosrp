<?php
declare(strict_types=1);

function mysqlTestConfig(): array {
    if (getenv('ENCONTRO_TEST_DATABASE') !== 'disposable') {
        throw new RuntimeException('Use ENCONTRO_TEST_DATABASE=disposable somente para testes locais.');
    }
    $host = getenv('DB_HOST');
    $name = getenv('DB_NAME');
    if (!in_array($host, ['127.0.0.1', 'localhost'], true)
        || !is_string($name) || strlen($name) > 64 || !preg_match('/^encontro_test_[a-zA-Z0-9_]+$/D', $name)) {
        throw new RuntimeException('Os testes exigem DB_HOST local e DB_NAME encontro_test_*.');
    }
    $port = getenv('DB_PORT');
    $port = $port === false ? '3306' : $port;
    if (!ctype_digit($port) || (int)$port < 1 || (int)$port > 65535) {
        throw new RuntimeException('DB_PORT inválida.');
    }
    $user = getenv('DB_USER');
    $password = getenv('DB_PASSWORD');
    return ['host' => $host, 'name' => $name, 'port' => $port,
        'user' => $user === false ? 'root' : $user, 'password' => $password === false ? '' : $password];
}

function mysqlTestPdo(bool $selectDatabase = true): PDO {
    $config = mysqlTestConfig();
    $dsn = 'mysql:host='.$config['host'].';port='.$config['port'].';charset=utf8mb4';
    if ($selectDatabase) $dsn .= ';dbname='.$config['name'];
    $pdo = new PDO($dsn, $config['user'], $config['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_TIMEOUT => 5,
    ]);
    $pdo->exec('SET SESSION innodb_lock_wait_timeout=10');
    return $pdo;
}

function mysqlTestCheck(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
    $GLOBALS['mysqlTestChecks'] = ($GLOBALS['mysqlTestChecks'] ?? 0) + 1;
}

function mysqlTestRejects(callable $operation, string $expectedClass, string $message): void {
    try {
        $operation();
    } catch (Throwable $error) {
        if ($error instanceof $expectedClass) {
            mysqlTestCheck(true, $message);
            return;
        }
        throw new RuntimeException($message.': exceção inesperada '.get_class($error), 0, $error);
    }
    throw new RuntimeException($message.': operação foi aceita.');
}

function mysqlTestSpace(PDO $pdo, int $ownerId, array $changes = []): array {
    $space = array_replace([
        'owner_id' => $ownerId, 'name' => 'Espaço exclusivo de teste', 'city' => 'Ribeirão Preto',
        'neighborhood' => 'Centro', 'address' => 'Endereço fictício', 'description' => 'Fixture local.',
        'rules' => 'Sem som depois das 22h.', 'capacity' => 100, 'daily_price' => '100.25',
        'weekend_price' => '180.50', 'type' => 'Salão', 'check_in_time' => '09:00:00',
        'check_out_time' => '09:00:00', 'minimum_days' => 1, 'cleanup_minutes' => 0,
        'active' => 1, 'is_demo' => 0,
    ], $changes);
    $columns = implode(',', array_keys($space));
    $placeholders = implode(',', array_fill(0, count($space), '?'));
    $statement = $pdo->prepare('INSERT INTO spaces ('.$columns.') VALUES ('.$placeholders.')');
    $statement->execute(array_values($space));
    $statement = $pdo->prepare('SELECT * FROM spaces WHERE id=?');
    $statement->execute([(int)$pdo->lastInsertId()]);
    return $statement->fetch(PDO::FETCH_ASSOC);
}

function mysqlTestInput(array $space, string $start, string $end, DateTimeImmutable $now, array $changes = []): array {
    $plan = $changes['plan'] ?? 'daily';
    $quote = bookingQuote($start, $end, $plan, (string)$space['daily_price'], $space['weekend_price'] === null ? null : (string)$space['weekend_price'], $now);
    return array_replace([
        'space_id' => (int)$space['id'], 'check_in' => $start, 'check_out' => $end, 'plan' => $plan,
        'contact_name' => 'Cliente de teste', 'phone' => '16999990000', 'guests' => 50,
        'message' => 'Evento fictício para testes, sem efeitos externos.', 'terms_accepted' => true,
        'terms_review' => ['name' => (string)$space['name'], 'city' => (string)$space['city'],
            'neighborhood' => (string)$space['neighborhood'], 'rules' => (string)($space['rules'] ?? ''),
            'total' => $quote['total']],
    ], $changes);
}

function mysqlTestRace(array $jobs, DateTimeImmutable $now): array {
    mysqlTestConfig();
    $workers = [];
    try {
        foreach ($jobs as $job) {
            $command = [PHP_BINARY];
            $configurationFile = php_ini_loaded_file();
            if ($configurationFile !== false) {
                array_push($command, '-c', $configurationFile);
            } else {
                $extensionDirectory = realpath((string)ini_get('extension_dir'));
                if ($extensionDirectory === false) throw new RuntimeException('Não foi possível localizar as extensões PHP para os workers.');
                array_push($command, '-d', 'extension_dir='.$extensionDirectory, '-d', 'extension=pdo_mysql', '-d', 'extension=mbstring');
            }
            array_push($command, '-d', 'max_execution_time=30', __DIR__.'/reservation-worker.php',
                base64_encode(json_encode($job + ['now' => $now->format(DateTimeInterface::ATOM)], JSON_THROW_ON_ERROR)));
            $process = proc_open($command, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, dirname(__DIR__));
            if (!is_resource($process)) throw new RuntimeException('Não foi possível iniciar o worker.');
            $workers[] = ['process' => $process, 'pipes' => $pipes];
        }
        foreach ($workers as $worker) {
            stream_set_timeout($worker['pipes'][1], 30);
            if (trim((string)fgets($worker['pipes'][1])) !== 'READY') {
                throw new RuntimeException('Worker não chegou à barreira: '.stream_get_contents($worker['pipes'][2]));
            }
        }
        foreach ($workers as $worker) {
            fwrite($worker['pipes'][0], "GO\n");
            fclose($worker['pipes'][0]);
        }
        $results = [];
        foreach ($workers as $index => $worker) {
            $output = stream_get_contents($worker['pipes'][1]);
            $error = stream_get_contents($worker['pipes'][2]);
            fclose($worker['pipes'][1]);
            fclose($worker['pipes'][2]);
            $exitCode = proc_close($worker['process']);
            unset($workers[$index]);
            if ($exitCode !== 0) throw new RuntimeException('Worker falhou: '.$error);
            $results[] = json_decode(trim($output), true, 512, JSON_THROW_ON_ERROR);
        }
        return $results;
    } finally {
        foreach ($workers as $worker) {
            foreach ($worker['pipes'] as $pipe) if (is_resource($pipe)) fclose($pipe);
            if (is_resource($worker['process'])) {
                proc_terminate($worker['process']);
                proc_close($worker['process']);
            }
        }
    }
}
