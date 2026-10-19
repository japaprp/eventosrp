<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/payment_lifecycle.php';

$cases = [
    ['pending','approved',null,'pay-1',false,'approve'],
    ['pending','approved',null,'pay-1',true,'refund_required'],
    ['expired','approved',null,'pay-1',false,'refund_required'],
    ['failed','approved',null,'pay-1',false,'refund_required'],
    ['cancelled','approved',null,'pay-1',false,'refund_required'],
    ['approved','approved','pay-1','pay-1',false,'idempotent_approval'],
    ['approved','approved','pay-1','pay-2',false,'refund_required'],
    ['pending','pending',null,'pay-1',false,'keep_hold'],
    ['pending','in_process',null,'pay-1',false,'keep_hold'],
    ['pending','rejected',null,'pay-1',false,'keep_until_preference_expiry'],
    ['pending','cancelled',null,'pay-1',false,'keep_until_preference_expiry'],
    ['approved','refunded','pay-1','pay-1',false,'refund_review'],
    ['approved','charged_back','pay-1','pay-1',false,'chargeback_review'],
    ['pending','new_provider_status',null,'pay-1',false,'keep_hold'],
];
foreach ($cases as [$reservationStatus,$paymentStatus,$existingId,$incomingId,$conflict,$expected]) {
    $actual = paymentLifecycleDecision($reservationStatus,$paymentStatus,$existingId,$incomingId,$conflict);
    if ($actual !== $expected) throw new RuntimeException("Transição inesperada: {$reservationStatus}/{$paymentStatus}; esperado {$expected}, recebido {$actual}");
}

$monotonicCases = [
    ['approved','approved','pending',true],
    ['approved','approved','rejected',true],
    ['approved','approved','refunded',false],
    ['approved','approved','charged_back',false],
    ['pending','approved','pending',false],
    ['approved','pending','rejected',false],
];
foreach ($monotonicCases as [$reservationStatus,$existingPaymentStatus,$incomingPaymentStatus,$expected]) {
    $actual = paymentLifecycleShouldPreserveApprovedStatus($reservationStatus,$existingPaymentStatus,$incomingPaymentStatus);
    if ($actual !== $expected) throw new RuntimeException('Uma atualização fora de ordem alterou indevidamente o estado financeiro.');
}
$now = new DateTimeImmutable('2026-10-19T12:00:00-03:00');
$expiredHold = '2026-10-19 11:00:00';
$futureHold = '2026-10-19 13:00:00';
$releaseCases = [
    ['pending',$expiredHold,true,[],true],
    ['pending',$expiredHold,true,[['status'=>'rejected'],['status'=>'cancelled']],true],
    ['pending',$futureHold,true,[],false],
    ['pending',$expiredHold,false,[],false],
    ['approved',$expiredHold,true,[],false],
    ['pending',$expiredHold,true,[['status'=>'approved']],false],
    ['pending',$expiredHold,true,[['status'=>'in_process']],false],
    ['pending',$expiredHold,true,[['status'=>'refunded']],false],
    ['pending',$expiredHold,true,[['status'=>'charged_back']],false],
    ['pending',$expiredHold,true,[['status'=>'unknown']],false],
];
foreach ($releaseCases as [$status,$deadline,$preferenceExpired,$payments,$expected]) {
    $actual = paymentLifecycleCanReleaseHold($status,$deadline,$preferenceExpired,$payments,$now);
    if ($actual !== $expected) throw new RuntimeException('Regra de liberação de reserva incorreta.');
}
echo count($cases).' decisões de estado e '.count($releaseCases).' regras de liberação passaram.\n';
