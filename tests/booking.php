<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/booking.php';
$now = new DateTimeImmutable('2026-10-01T08:00:00-03:00');
$cases = json_decode(file_get_contents(__DIR__.'/booking-cases.json'),true,512,JSON_THROW_ON_ERROR);
foreach ($cases as $case) {
    try {
        $result = bookingQuote($case['start'],$case['end'],$case['plan'],$case['daily'],$case['weekend'],$now);
        if (!empty($case['error'])) throw new RuntimeException('Deveria rejeitar: '.$case['label']);
        if ($result['days'] !== $case['days'] || $result['total'] !== $case['total']) throw new RuntimeException('Valor incorreto: '.$case['label']);
    } catch (InvalidArgumentException $e) {
        if (empty($case['error'])) throw new RuntimeException('Rejeição inesperada: '.$case['label'],0,$e);
    }
}
$overlaps = [
 ['2026-10-02 09:00:00','2026-10-04 09:00:00','2026-10-03 09:00:00','2026-10-05 09:00:00',true],
 ['2026-10-02 09:00:00','2026-10-04 09:00:00','2026-10-02 10:00:00','2026-10-02 18:00:00',true],
 ['2026-10-02 09:00:00','2026-10-04 09:00:00','2026-10-01 09:00:00','2026-10-05 09:00:00',true],
 ['2026-10-02 09:00:00','2026-10-04 09:00:00','2026-10-04 09:00:00','2026-10-05 09:00:00',false],
 ['2026-10-02 09:00:00','2026-10-04 09:00:00','2026-10-01 09:00:00','2026-10-02 09:00:00',false],
 ['2026-10-02 09:00:00','2026-10-04 09:00:00','2026-10-05 09:00:00','2026-10-06 09:00:00',false]
];
foreach ($overlaps as [$a,$b,$c,$d,$expected]) {
    if (bookingOverlaps($a,$b,$c,$d) !== $expected) throw new RuntimeException('Conflito de intervalo incorreto.');
}
echo count($cases)." testes de período/preço e ".count($overlaps)." testes de sobreposição passaram.\n";
