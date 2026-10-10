<?php
declare(strict_types=1);
require __DIR__.'/mysql-support.php';

try {
    mysqlTestConfig();
    require dirname(__DIR__).'/src/reservations.php';
    $input = json_decode(base64_decode($argv[1] ?? '', true) ?: '', true, 512, JSON_THROW_ON_ERROR);
    $pdo = mysqlTestPdo();
    echo "READY\n";
    flush();
    if (trim((string)fgets(STDIN)) !== 'GO') throw new RuntimeException('Barreira não liberada.');
    try {
        $reservation = createReservation($pdo, (int)$input['user_id'], $input['input'], new DateTimeImmutable($input['now']));
        $result = ['ok' => true, 'id' => $reservation['id']];
    } catch (ReservationConflictException|InvalidArgumentException $error) {
        $result = ['ok' => false, 'error_class' => get_class($error)];
    }
    echo json_encode($result, JSON_THROW_ON_ERROR)."\n";
} catch (Throwable $error) {
    fwrite(STDERR, get_class($error).': '.$error->getMessage()."\n");
    exit(1);
}
