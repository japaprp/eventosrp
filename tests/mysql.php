<?php
declare(strict_types=1);
require __DIR__.'/mysql-support.php';

$createdDatabase = null;
$databaseWasCreated = false;
$server = null;
try {
    $config = mysqlTestConfig();
    require dirname(__DIR__).'/src/reservations.php';
    require dirname(__DIR__).'/src/checkout.php';
    // Never load .env or import CREATE DATABASE/USE encontro from the installation schema.
    $createdDatabase = 'encontro_test_'.gmdate('Ymd_His').'_'.bin2hex(random_bytes(5));
    putenv('DB_NAME='.$createdDatabase);
    $server = mysqlTestPdo(false);
    $server->exec('CREATE DATABASE `'.$createdDatabase.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $databaseWasCreated = true;
    $pdo = mysqlTestPdo();
    $schema = file_get_contents(dirname(__DIR__).'/database/schema.sql');
    $schema = preg_replace('/\ACREATE DATABASE IF NOT EXISTS encontro CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\s*USE encontro;\s*/', '', $schema, 1, $removed);
    if ($removed !== 1 || preg_match('/\b(?:CREATE\s+DATABASE|USE)\b/i', $schema)) {
        throw new RuntimeException('Revise o schema antes de importá-lo no banco descartável.');
    }
    foreach (preg_split('/;\s*(?:\r?\n|$)/', trim($schema)) as $statement) {
        if (trim($statement) !== '') $pdo->exec($statement);
    }
    mysqlTestCheck($pdo->query('SELECT DATABASE()')->fetchColumn() === $createdDatabase, 'Schema deve usar somente o banco exclusivo desta execução.');
    $now = new DateTimeImmutable('2029-12-01T08:00:00-03:00');
    $userIds = [];
    $statement = $pdo->prepare('INSERT INTO users (firebase_uid,email,name,phone) VALUES (?,?,?,?)');
    foreach (['cliente-a', 'cliente-b', 'anfitriao'] as $uid) {
        $statement->execute(['local-test-'.$uid, $uid.'@example.test', 'Nome inicial', '16988880000']);
        $userIds[] = (int)$pdo->lastInsertId();
    }
    [$clientA, $clientB, $host] = $userIds;
    $demoSpace = mysqlTestSpace($pdo, $host, ['is_demo' => 1]);
    $demoInput = mysqlTestInput($demoSpace, '2030-01-04T09:00', '2030-01-06T09:00', $now);
    mysqlTestRejects(fn() => createReservation($pdo, $clientA, $demoInput, $now), InvalidArgumentException::class, 'Espaço demonstrativo não pode receber reserva.');
    $space = mysqlTestSpace($pdo, $host);
    $input = mysqlTestInput($space, '2030-01-04T09:00', '2030-01-06T09:00', $now);
    $notAccepted = $input;
    unset($notAccepted['terms_accepted']);
    mysqlTestRejects(fn() => createReservation($pdo, $clientA, $notAccepted, $now), InvalidArgumentException::class, 'Aceite ausente deve ser rejeitado.');
    $notReviewed = $input;
    unset($notReviewed['terms_review']);
    mysqlTestRejects(fn() => createReservation($pdo, $clientA, $notReviewed, $now), InvalidArgumentException::class, 'Revisão ausente deve ser rejeitada.');
    mysqlTestRejects(fn() => createReservation($pdo, $clientA, array_replace($input, ['guests' => 101]), $now), InvalidArgumentException::class, 'Capacidade não pode ser excedida.');
    mysqlTestRejects(fn() => createReservation($pdo, $clientA, array_replace($input, ['check_in' => '2030-01-04T10:00']), $now), InvalidArgumentException::class, 'Horário precisa respeitar o anúncio.');
    $staleRules = $input;
    $staleRules['terms_review']['rules'] = 'Regras antigas.';
    mysqlTestRejects(fn() => createReservation($pdo, $clientA, $staleRules, $now), ReservationConflictException::class, 'Regras diferentes das revisadas exigem novo aceite.');
    $staleTotal = $input;
    $staleTotal['terms_review']['total'] = '0.01';
    mysqlTestRejects(fn() => createReservation($pdo, $clientA, $staleTotal, $now), ReservationConflictException::class, 'Preço diferente do revisado exige nova revisão.');
    mysqlTestCheck((int)$pdo->query('SELECT COUNT(*) FROM reservations')->fetchColumn() === 0, 'Rejeições não podem gravar reservas.');
    $reservation = createReservation($pdo, $clientA, array_replace($input, ['total' => '0.01', 'daily_price' => '0.01']), $now);
    mysqlTestCheck($reservation['quote']['total'] === '200.50', 'Preço do MySQL deve prevalecer sobre valores enviados pelo cliente.');
    $statement = $pdo->prepare('SELECT * FROM reservations WHERE id=?');
    $statement->execute([$reservation['id']]);
    $saved = $statement->fetch(PDO::FETCH_ASSOC);
    mysqlTestCheck($saved['total'] === '200.50' && $saved['status'] === 'creating', 'Reserva deve persistir valor autoritativo e estado creating.');
    $snapshot = json_decode($saved['terms_snapshot'], true, 512, JSON_THROW_ON_ERROR);
    mysqlTestCheck($snapshot['host_rules'] === $space['rules'] && $snapshot['total'] === '200.50' && $snapshot['space']['name'] === $space['name'], 'Snapshot deve registrar condições e preço aceitos.');
    $statement = $pdo->prepare('SELECT name,phone FROM users WHERE id=?');
    $statement->execute([$clientA]);
    mysqlTestCheck($statement->fetch(PDO::FETCH_ASSOC) === ['name' => $input['contact_name'], 'phone' => $input['phone']], 'Contato do perfil deve ser atualizado na mesma transação.');
    $statement = $pdo->prepare('UPDATE spaces SET name=?,rules=?,daily_price=? WHERE id=?');
    $statement->execute(['Nome atualizado', 'Novas regras.', '120.25', $space['id']]);
    $statement = $pdo->prepare('SELECT terms_snapshot,total,blocked_until FROM reservations WHERE id=?');
    $statement->execute([$reservation['id']]);
    $afterEdit = $statement->fetch(PDO::FETCH_ASSOC);
    mysqlTestCheck($afterEdit['terms_snapshot'] === $saved['terms_snapshot'] && $afterEdit['total'] === $saved['total'] && $afterEdit['blocked_until'] === $saved['blocked_until'], 'Editar anúncio não pode alterar reserva e condições anteriores.');
    mysqlTestRejects(fn() => createReservation($pdo, $clientB, $input, $now), ReservationConflictException::class, 'Anúncio alterado após a revisão deve exigir revisão atualizada.');
    $statement = $pdo->prepare('SELECT * FROM spaces WHERE id=?');
    $statement->execute([$space['id']]);
    $currentSpace = $statement->fetch(PDO::FETCH_ASSOC);
    $overlap = mysqlTestInput($currentSpace, '2030-01-05T09:00', '2030-01-07T09:00', $now);
    mysqlTestRejects(fn() => createReservation($pdo, $clientB, $overlap, $now), ReservationConflictException::class, 'Sobreposição parcial deve ser rejeitada.');
    $boundary = mysqlTestInput($currentSpace, '2030-01-06T09:00', '2030-01-07T09:00', $now);
    $adjacent = createReservation($pdo, $clientB, $boundary, $now);
    mysqlTestCheck(isset($adjacent['id']), 'Sem preparação, entrada exatamente na saída anterior deve ser permitida.');

    $cleanupSpace = mysqlTestSpace($pdo, $host, ['cleanup_minutes' => 120]);
    $cleanupInput = mysqlTestInput($cleanupSpace, '2030-01-10T09:00', '2030-01-11T09:00', $now);
    $cleanupReservation = createReservation($pdo, $clientA, $cleanupInput, $now);
    $beforeCleanup = mysqlTestInput($cleanupSpace, '2030-01-11T09:00', '2030-01-12T09:00', $now);
    mysqlTestRejects(fn() => createReservation($pdo, $clientB, $beforeCleanup, $now), ReservationConflictException::class, 'Preparação deve bloquear entrada depois da saída e antes do limite.');
    $statement = $pdo->prepare('UPDATE spaces SET check_in_time=?,cleanup_minutes=0 WHERE id=?');
    $statement->execute(['11:00:00', $cleanupSpace['id']]);
    $cleanupSpace['check_in_time'] = '11:00:00';
    $cleanupSpace['cleanup_minutes'] = 0;
    $exactCleanup = mysqlTestInput($cleanupSpace, '2030-01-11T11:00', '2030-01-12T09:00', $now);
    $atCleanupBoundary = createReservation($pdo, $clientB, $exactCleanup, $now);
    mysqlTestCheck(isset($atCleanupBoundary['id']), 'Entrada exatamente ao fim da preparação registrada deve ser permitida.');
    $statement = $pdo->prepare('SELECT blocked_until FROM reservations WHERE id=?');
    $statement->execute([$cleanupReservation['id']]);
    mysqlTestCheck($statement->fetchColumn() === '2030-01-11 11:00:00', 'Preparação de reserva existente deve ser preservada após editar o anúncio.');

    $raceSpace = mysqlTestSpace($pdo, $host);
    $raceInput = mysqlTestInput($raceSpace, '2030-02-01T09:00', '2030-02-03T09:00', $now);
    $results = mysqlTestRace([['user_id' => $clientA, 'input' => $raceInput], ['user_id' => $clientB, 'input' => $raceInput]], $now);
    $successes = array_values(array_filter($results, static fn(array $result): bool => $result['ok'] === true));
    $conflicts = array_values(array_filter($results, static fn(array $result): bool => $result['ok'] === false && ($result['error_class'] ?? '') === ReservationConflictException::class));
    mysqlTestCheck(count($successes) === 1 && count($conflicts) === 1, 'Dois processos disputando o mesmo espaço devem gerar uma reserva e um conflito.');
    $statement = $pdo->prepare('SELECT COUNT(*) FROM reservations WHERE space_id=?');
    $statement->execute([$raceSpace['id']]);
    mysqlTestCheck((int)$statement->fetchColumn() === 1, 'Corrida deve persistir somente uma reserva.');

    $rollbackSpace = mysqlTestSpace($pdo, $host);
    $rollbackInput = mysqlTestInput($rollbackSpace, '2030-03-01T09:00', '2030-03-02T09:00', $now, ['contact_name' => 'Falha injetada']);
    $statement = $pdo->prepare('SELECT name,phone FROM users WHERE id=?');
    $statement->execute([$clientA]);
    $profileBefore = $statement->fetch(PDO::FETCH_ASSOC);
    $pdo->exec("CREATE TRIGGER test_profile_failure BEFORE UPDATE ON users FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='forced local rollback test'");
    try {
        mysqlTestRejects(fn() => createReservation($pdo, $clientA, $rollbackInput, $now), PDOException::class, 'Falha ao atualizar o perfil deve abortar a transação.');
    } finally {
        $pdo->exec('DROP TRIGGER test_profile_failure');
    }
    mysqlTestCheck(!$pdo->inTransaction(), 'Falha deve encerrar a transação com rollback.');
    $statement = $pdo->prepare('SELECT COUNT(*) FROM reservations WHERE space_id=?');
    $statement->execute([$rollbackSpace['id']]);
    mysqlTestCheck((int)$statement->fetchColumn() === 0, 'Rollback deve remover a reserva inserida antes da falha.');
    $statement = $pdo->prepare('SELECT name,phone FROM users WHERE id=?');
    $statement->execute([$clientA]);
    mysqlTestCheck($statement->fetch(PDO::FETCH_ASSOC) === $profileBefore, 'Rollback deve preservar o perfil anterior.');
    mysqlTestCheck((int)$pdo->query("SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='reservations'")->fetchColumn() === 2, 'Schema deve manter referências de reserva ao cliente e ao espaço.');

    $checkoutFixture = static function() use ($pdo, $host, $clientA, $now): array {
        $space = mysqlTestSpace($pdo, $host);
        $input = mysqlTestInput($space, '2030-05-01T09:00', '2030-05-02T09:00', $now);
        return [createReservation($pdo, $clientA, $input, $now), $input];
    };
    $readCheckout = static function(string $id) use ($pdo): array {
        $statement = $pdo->prepare('SELECT status,preference_id,payment_id FROM reservations WHERE id=?');
        $statement->execute([$id]);
        return $statement->fetch(PDO::FETCH_ASSOC);
    };
    $checkoutFailure = static function(array $reservation, callable $chat, callable $provider) use ($pdo): CheckoutUnavailableException {
        try {
            startReservationCheckout($pdo, $reservation, 'cliente@example.test', 'https://events.example.test', true, $chat, $provider);
        } catch (CheckoutUnavailableException $error) {
            return $error;
        }
        throw new RuntimeException('A falha simulada de checkout foi aceita.');
    };
    [$chatReservation, $chatInput] = $checkoutFixture();
    $providerCalled = false;
    $chatError = $checkoutFailure($chatReservation,
        static function(): void { throw new RuntimeException('Mock de chat indisponível.'); },
        static function(array $payload) use (&$providerCalled): array { $providerCalled = true; return []; });
    mysqlTestCheck(!$chatError->needsReconciliation && $chatError->reservationId === $chatReservation['id'], 'Falha de chat anterior ao POST não exige conciliação.');
    mysqlTestCheck(!$providerCalled && $readCheckout($chatReservation['id'])['status'] === 'failed', 'Falha de chat não chama provedor e marca a reserva failed.');
    $replacement = createReservation($pdo, $clientB, $chatInput, $now);
    mysqlTestCheck(isset($replacement['id']), 'Falha comprovadamente anterior ao POST deve liberar o intervalo.');

    [$timeoutReservation, $timeoutInput] = $checkoutFixture();
    $timeoutError = $checkoutFailure($timeoutReservation, static fn() => null,
        static function(array $payload): array { throw new RuntimeException('Mock de timeout após o POST.'); });
    mysqlTestCheck($timeoutError->needsReconciliation && $timeoutError->reservationId === $timeoutReservation['id'], 'Timeout depois da tentativa precisa informar conciliação e protocolo.');
    mysqlTestCheck($readCheckout($timeoutReservation['id'])['status'] === 'creating', 'Timeout não pode liberar uma preferência potencialmente criada.');
    mysqlTestRejects(fn() => createReservation($pdo, $clientB, $timeoutInput, $now), ReservationConflictException::class, 'Reserva com checkout incerto deve continuar bloqueando o intervalo.');

    [$invalidReservation, $invalidInput] = $checkoutFixture();
    $invalidError = $checkoutFailure($invalidReservation, static fn() => null,
        static fn(array $payload): array => ['id' => 'mock-invalid', 'sandbox_init_point' => 'http://payments.example.test/invalid']);
    mysqlTestCheck($invalidError->needsReconciliation && $readCheckout($invalidReservation['id'])['status'] === 'creating', 'Resposta inválida após o POST não pode marcar a reserva failed.');
    mysqlTestRejects(fn() => createReservation($pdo, $clientB, $invalidInput, $now), ReservationConflictException::class, 'Resposta inválida deve preservar o bloqueio do intervalo.');

    [$successReservation, $successInput] = $checkoutFixture();
    $payloadSeen = null;
    $checkout = startReservationCheckout($pdo, $successReservation, 'cliente@example.test', 'https://events.example.test', true,
        static fn() => null,
        static function(array $payload) use (&$payloadSeen): array {
            $payloadSeen = $payload;
            return ['id' => 'mock-success', 'sandbox_init_point' => 'https://payments.example.test/sandbox', 'init_point' => 'https://payments.example.test/live'];
        });
    mysqlTestCheck($payloadSeen['items'][0]['unit_price'] === 100.25 && $payloadSeen['items'][0]['currency_id'] === 'BRL'
        && $payloadSeen['items'][0]['quantity'] === 1 && $payloadSeen['external_reference'] === $successReservation['id'], 'Payload deve usar preço do quote, moeda, quantidade e referência da reserva.');
    mysqlTestCheck($checkout === ['reservation_id' => $successReservation['id'], 'checkout_url' => 'https://payments.example.test/sandbox'], 'Checkout em sandbox deve retornar o link de teste e protocolo.');
    mysqlTestCheck($readCheckout($successReservation['id']) === ['status' => 'pending', 'preference_id' => 'mock-success', 'payment_id' => null], 'Checkout válido deve persistir preferência e status pending.');
    mysqlTestRejects(fn() => createReservation($pdo, $clientB, $successInput, $now), ReservationConflictException::class, 'Reserva com checkout pending deve preservar o bloqueio.');

    [$approvedReservation, $approvedInput] = $checkoutFixture();
    $approvedCheckout = startReservationCheckout($pdo, $approvedReservation, 'cliente@example.test', 'https://events.example.test', false,
        static fn() => null,
        static function(array $payload) use ($pdo, $approvedReservation): array {
            // Simulate an authenticated approval arriving before the preference POST returns.
            $statement = $pdo->prepare("UPDATE reservations SET status='approved',payment_id=? WHERE id=?");
            $statement->execute(['mock-approved-payment', $approvedReservation['id']]);
            return ['id' => 'mock-approved-preference', 'sandbox_init_point' => 'https://payments.example.test/sandbox', 'init_point' => 'https://payments.example.test/live'];
        });
    mysqlTestCheck($approvedCheckout['checkout_url'] === 'https://payments.example.test/live', 'Seleção de link deve respeitar a opção sandbox.');
    mysqlTestCheck($readCheckout($approvedReservation['id']) === ['status' => 'approved', 'preference_id' => 'mock-approved-preference', 'payment_id' => 'mock-approved-payment'], 'Aprovação anterior ao retorno deve ser preservada, com preferência registrada.');
    mysqlTestRejects(fn() => createReservation($pdo, $clientB, $approvedInput, $now), ReservationConflictException::class, 'Reserva aprovada deve preservar o bloqueio.');

    echo $GLOBALS['mysqlTestChecks']." verificações MySQL passaram: revisão, preço, snapshot, preparação, concorrência, rollback e checkout simulado.\n";
} catch (Throwable $error) {
    fwrite(STDERR, get_class($error).': '.$error->getMessage()."\n");
    $failed = true;
} finally {
    if ($databaseWasCreated && $createdDatabase !== null && $server instanceof PDO) {
        // This exact name was generated above; never delete an existing installation database.
        $config = mysqlTestConfig();
        if ($config['name'] !== $createdDatabase || !preg_match('/^encontro_test_\d{8}_\d{6}_[a-f0-9]{10}$/D', $createdDatabase)) {
            throw new RuntimeException('Nome do banco descartável mudou; remoção bloqueada.');
        }
        $server->exec('DROP DATABASE `'.$createdDatabase.'`');
    }
}
exit(isset($failed) ? 1 : 0);
