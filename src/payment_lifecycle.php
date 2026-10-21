<?php
declare(strict_types=1);

/**
 * Pure reservation/payment state decisions. Unknown provider states are conservative:
 * they must not release a date or overwrite an already-approved reservation.
 */
function paymentLifecycleDecision(
    string $reservationStatus,
    string $paymentStatus,
    ?string $existingPaymentId = null,
    ?string $incomingPaymentId = null,
    bool $hasConflictingReservation = false
): string {
    if ($paymentStatus === 'approved') {
        if ($reservationStatus === 'approved') {
            return $existingPaymentId !== null && $existingPaymentId === $incomingPaymentId
                ? 'idempotent_approval'
                : 'refund_required';
        }
        if ($reservationStatus === 'pending' && !$hasConflictingReservation) {
            return 'approve';
        }
        return 'refund_required';
    }

    if ($reservationStatus === 'approved') {
        if ($paymentStatus === 'charged_back') return 'chargeback_review';
        if ($paymentStatus === 'refunded') return 'refund_review';
        return 'preserve_approved_booking';
    }

    if ($paymentStatus === 'charged_back') return 'chargeback_review';
    if ($paymentStatus === 'refunded') return 'refund_review';
    if (in_array($paymentStatus, ['rejected', 'cancelled'], true)) return 'keep_until_preference_expiry';

    return 'keep_hold';
}

function paymentLifecycleCanReleaseHold(
    string $reservationStatus,
    ?string $holdExpiresAt,
    bool $preferenceExpired,
    array $payments,
    DateTimeImmutable $now
): bool {
    if ($reservationStatus !== 'pending' || $holdExpiresAt === null || !$preferenceExpired) return false;

    try {
        $holdDeadline = new DateTimeImmutable($holdExpiresAt, new DateTimeZone('America/Sao_Paulo'));
    } catch (Throwable) {
        return false;
    }
    if ($holdDeadline > $now) return false;

    foreach ($payments as $payment) {
        $status = is_array($payment) ? ($payment['status'] ?? null) : null;
        // Only explicit, terminal non-payment outcomes permit automatic release.
        if (!in_array($status, ['rejected', 'cancelled'], true)) return false;
    }
    return true;
}

/**
 * A late or out-of-order non-terminal event must not downgrade an already approved payment.
 * Refunds and chargebacks are explicit financial outcomes and must still be processed.
 */
function paymentLifecycleShouldPreserveApprovedStatus(
    string $reservationStatus,
    ?string $existingPaymentStatus,
    string $incomingPaymentStatus
): bool {
    return $reservationStatus === 'approved'
        && $existingPaymentStatus === 'approved'
        && !in_array($incomingPaymentStatus, ['refunded', 'charged_back'], true);
}


/**
 * Keep the audit aggregate monotonic for delayed/out-of-order provider updates.
 * Refund/chargeback are financial outcomes; chargeback is retained over refund.
 */
function paymentLifecycleAuditStatus(string $currentStatus, string $incomingStatus): string {
    if ($currentStatus === 'charged_back' || $incomingStatus === 'charged_back') return 'charged_back';
    if ($currentStatus === 'refunded' || $incomingStatus === 'refunded') return 'refunded';
    if ($currentStatus === 'approved' || $incomingStatus === 'approved') return 'approved';
    return $incomingStatus;
}


function paymentLifecycleRecordEvent(PDO $pdo, string $reservationId, array $payment): void {
 $paymentId=(string)($payment['id']??'');$status=(string)($payment['status']??'');
 if($paymentId===''||$status==='')throw new InvalidArgumentException('Evento de pagamento incompleto.');
 $now=(new DateTimeImmutable('now',new DateTimeZone('America/Sao_Paulo')))->format('Y-m-d H:i:s');
 $detail=isset($payment['status_detail'])?substr((string)$payment['status_detail'],0,100):null;
 $q=$pdo->prepare("INSERT INTO reservation_payment_events(reservation_id,payment_id,provider_status,status_detail,amount,currency,event_count,first_seen_at,last_seen_at) VALUES (?,?,?,?,?,?,1,?,?) ON DUPLICATE KEY UPDATE status_detail=CASE WHEN provider_status='charged_back' THEN status_detail WHEN VALUES(provider_status)='charged_back' THEN VALUES(status_detail) WHEN provider_status='refunded' THEN status_detail WHEN VALUES(provider_status)='refunded' THEN VALUES(status_detail) WHEN provider_status='approved' THEN status_detail WHEN VALUES(provider_status)='approved' THEN VALUES(status_detail) ELSE VALUES(status_detail) END,provider_status=CASE WHEN provider_status='charged_back' THEN provider_status WHEN VALUES(provider_status)='charged_back' THEN VALUES(provider_status) WHEN provider_status='refunded' THEN provider_status WHEN VALUES(provider_status)='refunded' THEN VALUES(provider_status) WHEN provider_status='approved' THEN provider_status WHEN VALUES(provider_status)='approved' THEN VALUES(provider_status) ELSE VALUES(provider_status) END,event_count=event_count+1,last_seen_at=VALUES(last_seen_at)");
 $q->execute([$reservationId,$paymentId,$status,$detail,number_format((float)($payment['transaction_amount']??0),2,'.',''),(string)($payment['currency_id']??''),$now,$now]);
}
function paymentLifecycleApplyVerifiedPayment(PDO $pdo, string $reservationId, array $payment): void {
 $paymentId=(string)($payment['id']??'');$status=(string)($payment['status']??'');
 if($paymentId===''||$status===''||(string)($payment['external_reference']??'')!==$reservationId)throw new InvalidArgumentException('Referência do pagamento inconsistente.');
 $initial=$pdo->prepare('SELECT space_id FROM reservations WHERE id=?');$initial->execute([$reservationId]);$spaceId=$initial->fetchColumn();
 if(!$spaceId)return;
 $pdo->beginTransaction();
 try{
  $lock=$pdo->prepare('SELECT id FROM spaces WHERE id=? FOR UPDATE');$lock->execute([$spaceId]);
  $q=$pdo->prepare('SELECT * FROM reservations WHERE id=? FOR UPDATE');$q->execute([$reservationId]);$reservation=$q->fetch(PDO::FETCH_ASSOC);
  if(!$reservation){$pdo->commit();return;}
  if(($payment['currency_id']??'')!=='BRL'||abs((float)($payment['transaction_amount']??-1)-(float)$reservation['total'])>0.001)throw new InvalidArgumentException('Valor ou moeda do pagamento inconsistente.');
  paymentLifecycleRecordEvent($pdo,$reservationId,$payment);
  $existingId=isset($reservation['payment_id'])?(string)$reservation['payment_id']:null;
  $conflict=false;
  if($status==='approved'&&$reservation['status']==='pending'){
   $q=$pdo->prepare("SELECT id FROM reservations WHERE space_id=? AND id<>? AND check_in < ? AND blocked_until > ? AND status IN ('creating','pending','approved') LIMIT 1");
   $q->execute([$reservation['space_id'],$reservationId,$reservation['blocked_until'],$reservation['check_in']]);$conflict=(bool)$q->fetchColumn();
  }
  $decision=($status==='approved'&&$reservation['status']==='pending'&&$reservation['payment_attention']!=='none')?'refund_required':paymentLifecycleDecision((string)$reservation['status'],$status,$existingId,$paymentId,$conflict);
  $detail=isset($payment['status_detail'])?substr((string)$payment['status_detail'],0,100):null;
  $now=(new DateTimeImmutable('now',new DateTimeZone('America/Sao_Paulo')))->format('Y-m-d H:i:s');
  if($decision==='approve'){
   $q=$pdo->prepare("UPDATE reservations SET status='approved',payment_id=?,payment_status=?,payment_status_detail=?,payment_updated_at=? WHERE id=? AND status='pending'");
   $q->execute([$paymentId,$status,$detail,$now,$reservationId]);
  }elseif($decision==='idempotent_approval'){
   $q=$pdo->prepare("UPDATE reservations SET payment_status='approved',payment_status_detail=?,payment_updated_at=? WHERE id=? AND status='approved' AND payment_id=?");
   $q->execute([$detail,$now,$reservationId,$paymentId]);
  }elseif($decision==='refund_required'){
   if($reservation['status']==='pending'&&$status==='approved'&&$conflict){
    $q=$pdo->prepare("UPDATE reservations SET status='failed',payment_status='approved',payment_status_detail=?,payment_updated_at=?,payment_attention='refund_required' WHERE id=? AND status='pending'");
    $q->execute([$detail,$now,$reservationId]);
   }elseif(in_array($reservation['status'],['expired','failed','cancelled','creating'],true)){
    $q=$pdo->prepare("UPDATE reservations SET payment_status=?,payment_status_detail=?,payment_updated_at=?,payment_attention='refund_required' WHERE id=?");
    $q->execute([$status,$detail,$now,$reservationId]);
   }else{
    $q=$pdo->prepare("UPDATE reservations SET payment_attention='refund_required',payment_updated_at=? WHERE id=?");
    $q->execute([$now,$reservationId]);
   }
  }elseif($decision==='chargeback_review'||$decision==='refund_review'){
   $attention=$decision==='chargeback_review'?'chargeback':'review_required';
   if($existingId===null||$existingId===$paymentId){
    $q=$pdo->prepare('UPDATE reservations SET payment_status=?,payment_status_detail=?,payment_updated_at=?,payment_attention=? WHERE id=?');
    $q->execute([$status,$detail,$now,$attention,$reservationId]);
   }else{
    $q=$pdo->prepare('UPDATE reservations SET payment_attention=?,payment_updated_at=? WHERE id=?');
    $q->execute([$attention,$now,$reservationId]);
   }
  }elseif($decision==='preserve_approved_booking'){
   if($existingId===$paymentId){
    // Never let a delayed/older non-terminal event downgrade a payment already recorded as approved.
    // Explicit refund/chargeback outcomes remain actionable and require review.
    if(paymentLifecycleShouldPreserveApprovedStatus((string)$reservation['status'],$reservation['payment_status']===null?null:(string)$reservation['payment_status'],$status)){
     // Preserve the approved financial state; the event remains recorded for audit.
    }else{
     $attention=in_array($status,['refunded','charged_back'],true)?($status==='charged_back'?'chargeback':'review_required'):'none';
     $q=$pdo->prepare('UPDATE reservations SET payment_status=?,payment_status_detail=?,payment_updated_at=?,payment_attention=? WHERE id=?');
     $q->execute([$status,$detail,$now,$attention,$reservationId]);
    }
   }
  }elseif(in_array($decision,['keep_hold','keep_until_preference_expiry'],true)){
   if($reservation['status']==='pending'&&($existingId===null||$existingId===$paymentId)){
    $q=$pdo->prepare('UPDATE reservations SET payment_status=?,payment_status_detail=?,payment_updated_at=? WHERE id=? AND status=\'pending\'');
    $q->execute([$status,$detail,$now,$reservationId]);
   }
  }
  $pdo->commit();
 }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

