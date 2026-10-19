<?php
declare(strict_types=1);

/**
 * Pure financial calculations for reviewable marketplace estimates.
 * Amounts are decimal strings and integer cents; no binary floating-point math.
 * These helpers do not charge a customer or create a provider split.
 */
function marketplaceMoneyToCents(string $amount): int {
    if (!preg_match('/^(?:0|[1-9][0-9]{0,7})(?:\\.[0-9]{1,2})?$/', $amount)) {
        throw new InvalidArgumentException('Valor monetário inválido.');
    }
    [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');
    return ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
}

function marketplaceCentsToMoney(int $cents): string {
    $sign = $cents < 0 ? '-' : '';
    $absolute = abs($cents);
    return $sign . intdiv($absolute, 100) . '.' . str_pad((string) ($absolute % 100), 2, '0', STR_PAD_LEFT);
}

/**
 * Commission is currently a proposed percentage of the gross reservation.
 * Provider fee is recorded separately; final settlement must use actual provider data.
 */
function marketplaceCommissionQuote(string $grossAmount, int $rateBasisPoints, string $providerFeeAmount = '0.00'): array {
    $gross = marketplaceMoneyToCents($grossAmount);
    $providerFee = marketplaceMoneyToCents($providerFeeAmount);
    if ($gross < 1 || $rateBasisPoints < 0 || $rateBasisPoints > 3000 || $providerFee > $gross) {
        throw new InvalidArgumentException('Parâmetros da comissão fora dos limites permitidos.');
    }
    $commission = intdiv(($gross * $rateBasisPoints) + 5000, 10000);
    $hostNet = $gross - $providerFee - $commission;
    if ($hostNet < 0) {
        throw new InvalidArgumentException('A comissão supera o saldo estimado do anfitrião.');
    }
    return [
        'currency' => 'BRL',
        'gross_amount' => marketplaceCentsToMoney($gross),
        'provider_fee_amount' => marketplaceCentsToMoney($providerFee),
        'commission_rate_bps' => $rateBasisPoints,
        'commission_amount' => marketplaceCentsToMoney($commission),
        'host_net_estimate' => marketplaceCentsToMoney($hostNet),
        'basis' => 'gross_reservation_amount',
        'is_estimate' => true,
    ];
}

function marketplacePromotionQuote(string $dailyPrice, int $days): array {
    $daily = marketplaceMoneyToCents($dailyPrice);
    if ($daily < 1 || $days < 1 || $days > 30) {
        throw new InvalidArgumentException('Promoção deve durar de 1 a 30 dias e ter preço positivo.');
    }
    return [
        'currency' => 'BRL',
        'duration_days' => $days,
        'daily_price' => marketplaceCentsToMoney($daily),
        'total_amount' => marketplaceCentsToMoney($daily * $days),
        'sponsored_label' => 'Patrocinado',
        'is_active' => false,
    ];
}

/** Stable key to make ledger writes idempotent; do not use user-controlled text directly as a DB key. */
function marketplaceLedgerIdempotencyKey(string $entryType, string $providerPaymentId, string $providerEventId): string {
    if (!in_array($entryType, ['commission_accrual', 'refund_reversal', 'chargeback_reversal', 'manual_adjustment'], true)
        || trim($providerPaymentId) === '' || trim($providerEventId) === '') {
        throw new InvalidArgumentException('Referência financeira incompleta.');
    }
    return hash('sha256', $entryType . "\0" . $providerPaymentId . "\0" . $providerEventId);
}

function marketplaceSignedMoneyToCents(string $amount): int {
    if (!preg_match('/^-?(?:0|[1-9][0-9]{0,7})(?:\\.[0-9]{1,2})?$/', $amount)) {
        throw new InvalidArgumentException('Valor contábil inválido.');
    }
    $negative = str_starts_with($amount, '-');
    $absolute = $negative ? substr($amount, 1) : $amount;
    $cents = marketplaceMoneyToCents($absolute);
    return $negative ? -$cents : $cents;
}

/**
 * Append one immutable financial event. Reversals are separate rows; a repeated
 * idempotency key returns the original row only if the financial payload matches.
 */
function marketplaceRecordLedgerEntry(PDO $pdo, array $entry): int {
    $reservationId = (string)($entry['reservation_id'] ?? '');
    $ownerId = filter_var($entry['owner_id'] ?? null, FILTER_VALIDATE_INT);
    $paymentId = trim((string)($entry['provider_payment_id'] ?? ''));
    $eventId = trim((string)($entry['provider_event_id'] ?? ''));
    $type = (string)($entry['entry_type'] ?? '');
    if (!preg_match('/^[a-f0-9]{32}$/', $reservationId) || !$ownerId || $ownerId < 1
        || $paymentId === '' || strlen($paymentId) > 100 || $eventId === '' || strlen($eventId) > 150) {
        throw new InvalidArgumentException('Referências do ledger inválidas.');
    }
    $ownership = $pdo->prepare('SELECT s.owner_id FROM reservations r JOIN spaces s ON s.id=r.space_id WHERE r.id=?');
    $ownership->execute([$reservationId]);
    $actualOwner = $ownership->fetchColumn();
    if ($actualOwner === false || $actualOwner === null || (int)$actualOwner !== (int)$ownerId) {
        throw new InvalidArgumentException('A reserva não pertence ao anfitrião informado.');
    }
    $key = marketplaceLedgerIdempotencyKey($type, $paymentId, $eventId);
    $gross = marketplaceSignedMoneyToCents((string)($entry['gross_amount'] ?? ''));
    $commission = marketplaceSignedMoneyToCents((string)($entry['commission_amount'] ?? ''));
    $providerFee = array_key_exists('provider_fee_amount', $entry) && $entry['provider_fee_amount'] !== null
        ? marketplaceSignedMoneyToCents((string)$entry['provider_fee_amount']) : null;
    $hostNet = array_key_exists('host_net_estimate', $entry) && $entry['host_net_estimate'] !== null
        ? marketplaceSignedMoneyToCents((string)$entry['host_net_estimate']) : null;
    $rate = $entry['commission_rate_bps'] ?? null;
    if ($rate !== null && (!is_int($rate) || $rate < 0 || $rate > 3000)) {
        throw new InvalidArgumentException('Taxa do ledger inválida.');
    }
    if ($type === 'commission_accrual' && $commission < 0) {
        throw new InvalidArgumentException('Acréscimo de comissão não pode ser negativo.');
    }
    if (in_array($type, ['refund_reversal','chargeback_reversal'], true) && $commission > 0) {
        throw new InvalidArgumentException('Estorno de comissão precisa ter valor negativo ou zero.');
    }
    $reverses = $entry['reverses_entry_id'] ?? null;
    if ($reverses !== null && (!filter_var($reverses, FILTER_VALIDATE_INT) || $reverses < 1)) {
        throw new InvalidArgumentException('Referência de estorno inválida.');
    }
    $metadata = $entry['metadata'] ?? null;
    if ($metadata !== null && !is_array($metadata)) throw new InvalidArgumentException('Metadados do ledger inválidos.');
    $metadataJson = $metadata === null ? null : json_encode($metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    $values = [
        $reservationId,(int)$ownerId,$paymentId,$eventId,$type,'BRL',
        marketplaceCentsToMoney($gross),$providerFee === null ? null : marketplaceCentsToMoney($providerFee),
        $rate,$commission === 0 ? '0.00' : marketplaceCentsToMoney($commission),
        $hostNet === null ? null : marketplaceCentsToMoney($hostNet),$key,$reverses,$metadataJson,
    ];
    try {
        $q = $pdo->prepare('INSERT INTO marketplace_commission_ledger(reservation_id,owner_id,provider_payment_id,provider_event_id,entry_type,currency,gross_amount,provider_fee_amount,commission_rate_bps,commission_amount,host_net_estimate,idempotency_key,reverses_entry_id,metadata) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $q->execute($values);
        return (int)$pdo->lastInsertId();
    } catch (PDOException $e) {
        $q = $pdo->prepare('SELECT id,reservation_id,owner_id,provider_payment_id,provider_event_id,entry_type,gross_amount,provider_fee_amount,commission_rate_bps,commission_amount,host_net_estimate,reverses_entry_id,metadata FROM marketplace_commission_ledger WHERE idempotency_key=?');
        $q->execute([$key]);
        $existing = $q->fetch(PDO::FETCH_ASSOC);
        if (!$existing) throw $e;
        $matches = $existing['reservation_id'] === $reservationId
            && (int)$existing['owner_id'] === (int)$ownerId
            && $existing['provider_payment_id'] === $paymentId
            && $existing['provider_event_id'] === $eventId
            && $existing['entry_type'] === $type
            && marketplaceSignedMoneyToCents((string)$existing['gross_amount']) === $gross
            && (($existing['provider_fee_amount'] === null ? null : marketplaceSignedMoneyToCents((string)$existing['provider_fee_amount'])) === $providerFee)
            && (($existing['commission_rate_bps'] === null ? null : (int)$existing['commission_rate_bps']) === $rate)
            && marketplaceSignedMoneyToCents((string)$existing['commission_amount']) === $commission
            && (($existing['host_net_estimate'] === null ? null : marketplaceSignedMoneyToCents((string)$existing['host_net_estimate'])) === $hostNet)
            && (($existing['reverses_entry_id'] === null ? null : (int)$existing['reverses_entry_id']) === ($reverses === null ? null : (int)$reverses))
            && (($existing['metadata'] === null ? null : (string)$existing['metadata']) === $metadataJson);
        if (!$matches) throw new RuntimeException('Colisão de idempotência no ledger financeiro.');
        return (int)$existing['id'];
    }
}
