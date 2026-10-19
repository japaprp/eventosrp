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
