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

/**
 * Atomically expire a pending reservation while taking the same space row lock
 * used by verified webhook payment updates. Re-read the reservation only after
 * acquiring the space lock so a concurrent approval cannot be overwritten.
 */
function paymentLifecycleExpirePendingReservation(PDO $pdo, string $reservationId, DateTimeImmutable $now): bool {
    if ($reservationId === '' || $pdo->inTransaction()) return false;
    $pdo->beginTransaction();
    try {
        $lookup = $pdo->prepare('SELECT space_id FROM reservations WHERE id = ?');
        $lookup->execute([$reservationId]);
        $spaceId = $lookup->fetchColumn();
        if (!$spaceId) {
            $pdo->commit();
            return false;
        }

        $spaceLock = $pdo->prepare('SELECT id FROM spaces WHERE id = ? FOR UPDATE');
        $spaceLock->execute([$spaceId]);
        if (!$spaceLock->fetchColumn()) {
            $pdo->commit();
            return false;
        }

        $reservationQuery = $pdo->prepare('SELECT status, payment_attention, hold_expires_at FROM reservations WHERE id = ? FOR UPDATE');
        $reservationQuery->execute([$reservationId]);
        $reservation = $reservationQuery->fetch(PDO::FETCH_ASSOC);
        if (!$reservation
            || $reservation['status'] !== 'pending'
            || $reservation['payment_attention'] !== 'none'
            || empty($reservation['hold_expires_at'])
            || $reservation['hold_expires_at'] > $now->format('Y-m-d H:i:s')) {
            $pdo->commit();
            return false;
        }

        $update = $pdo->prepare("UPDATE reservations SET status = 'expired' WHERE id = ? AND status = 'pending' AND payment_attention = 'none' AND hold_expires_at <= ?");
        $update->execute([$reservationId, $now->format('Y-m-d H:i:s')]);
        $expired = $update->rowCount() === 1;
        $pdo->commit();
        return $expired;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
