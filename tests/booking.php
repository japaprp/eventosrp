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
$space = ['id'=>12,'name'=>'Chácara Teste','address'=>'Rua Exemplo, 10','city'=>'Ribeirão Preto','rules'=>'Sem som após 22h. Cancelamento conforme política publicada.','check_in_time'=>'09:00:00','check_out_time'=>'09:00:00'];
$quote = ['check_in'=>'2026-10-02 09:00:00','check_out'=>'2026-10-04 09:00:00','days'=>2,'total'=>'2000.00'];
$reservation = ['plan'=>'daily','contact_name'=>'Cliente Teste','phone'=>'16999999999','guests'=>30,'message'=>'Aniversário familiar'];
$snapshot = json_decode(reservationAgreementSnapshot($space,$quote,$reservation,RESERVATION_TERMS_VERSION),true,512,JSON_THROW_ON_ERROR);
if ($snapshot['version'] !== RESERVATION_TERMS_VERSION || $snapshot['space']['rules_and_cancellation'] !== $space['rules'] || $snapshot['reservation']['total_brl'] !== '2000.00' || $snapshot['reservation']['guests'] !== 30) throw new RuntimeException('Snapshot do aceite não preservou os dados essenciais.');
echo count($cases)." testes de período/preço, ".count($overlaps)." testes de sobreposição e 1 teste de snapshot do aceite passaram.\n";
