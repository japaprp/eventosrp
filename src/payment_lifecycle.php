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
 * Run one reservation reconciliation under a connection-scoped MySQL advisory lock.
 * Returns false when another worker already owns the lock.
 */
function paymentLifecycleWithAdvisoryLock(PDO $pdo, string $reservationId, callable $operation): bool {
    if ($reservationId === '') return false;
    $lockName = 'eventosrp_reconcile_' . substr(hash('sha256', $reservationId), 0, 40);
    $lockQuery = $pdo->prepare('SELECT GET_LOCK(?, 0)');
    $lockQuery->execute([$lockName]);
    if ((int) $lockQuery->fetchColumn() !== 1) return false;
    try {
        $operation();
        return true;
    } finally {
        $unlock = $pdo->prepare('SELECT RELEASE_LOCK(?)');
        $unlock->execute([$lockName]);
    }
}
