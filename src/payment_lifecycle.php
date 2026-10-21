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
