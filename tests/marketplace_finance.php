<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/marketplace_finance.php';

function expectSame(mixed $expected, mixed $actual, string $message): void {
    if ($expected !== $actual) throw new RuntimeException($message . ': esperado ' . var_export($expected, true) . ', recebido ' . var_export($actual, true));
}
function expectInvalid(callable $operation, string $message): void {
    try { $operation(); } catch (InvalidArgumentException) { return; }
    throw new RuntimeException($message . ': deveria rejeitar a entrada.');
}

$eight = marketplaceCommissionQuote('1000.00', 800, '32.50');
expectSame('80.00', $eight['commission_amount'], 'Comissão de 8%');
expectSame('887.50', $eight['host_net_estimate'], 'Líquido estimado com tarifa');
expectSame(true, $eight['is_estimate'], 'Cálculo precisa ser marcado como estimativa');

$ten = marketplaceCommissionQuote('1000.00', 1000, '32.50');
expectSame('100.00', $ten['commission_amount'], 'Comissão de 10%');
expectSame('867.50', $ten['host_net_estimate'], 'Líquido estimado com 10%');

$rounded = marketplaceCommissionQuote('19.99', 800);
expectSame('1.60', $rounded['commission_amount'], 'Arredondamento para centavos');
expectSame('18.39', $rounded['host_net_estimate'], 'Líquido sem tarifa');

$promotion = marketplacePromotionQuote('15.00', 3);
expectSame('45.00', $promotion['total_amount'], 'Preço da promoção');
expectSame('Patrocinado', $promotion['sponsored_label'], 'Identificação de anúncio patrocinado');
expectSame(false, $promotion['is_active'], 'Orçamento não pode ativar anúncio');

expectInvalid(fn() => marketplaceCommissionQuote('100.00', 1000, '101.00'), 'Tarifa maior que o bruto');
expectInvalid(fn() => marketplaceCommissionQuote('100.00', 3001), 'Taxa de comissão fora do limite');
expectInvalid(fn() => marketplaceCommissionQuote('-1.00', 800), 'Valor negativo');
expectInvalid(fn() => marketplaceMoneyToCents('1.999'), 'Mais de duas casas decimais');
expectInvalid(fn() => marketplacePromotionQuote('0.00', 1), 'Preço zero');
expectInvalid(fn() => marketplacePromotionQuote('15.00', 31), 'Mais de 30 dias');
expectInvalid(fn() => marketplaceLedgerIdempotencyKey('commission_accrual', '', 'event-1'), 'Referência vazia');

$keyA = marketplaceLedgerIdempotencyKey('commission_accrual', 'pay-123', 'approved-event');
$keyB = marketplaceLedgerIdempotencyKey('commission_accrual', 'pay-123', 'approved-event');
$keyC = marketplaceLedgerIdempotencyKey('refund_reversal', 'pay-123', 'approved-event');
expectSame($keyA, $keyB, 'Chave idempotente');
if ($keyA === $keyC) throw new RuntimeException('Tipos de evento distintos precisam de chaves distintas.');

echo "Cálculos de comissão, anúncios e idempotência passaram.\n";
