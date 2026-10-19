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
function expectThrows(callable $operation, string $message): void {
    try { $operation(); } catch (Throwable) { return; }
    throw new RuntimeException($message . ': deveria lançar exceção.');
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

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY); CREATE TABLE spaces (id INTEGER PRIMARY KEY, owner_id INTEGER); CREATE TABLE reservations (id TEXT PRIMARY KEY, space_id INTEGER); INSERT INTO users(id) VALUES (7); INSERT INTO spaces(id,owner_id) VALUES (1,7); INSERT INTO reservations(id,space_id) VALUES (\'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa\',1); CREATE TABLE marketplace_commission_ledger (
 id INTEGER PRIMARY KEY AUTOINCREMENT, reservation_id TEXT, owner_id INTEGER, provider_payment_id TEXT,
 provider_event_id TEXT, entry_type TEXT, currency TEXT, gross_amount TEXT, provider_fee_amount TEXT,
 commission_rate_bps INTEGER, commission_amount TEXT, host_net_estimate TEXT, idempotency_key TEXT UNIQUE,
 reverses_entry_id INTEGER, metadata TEXT
)');
$entry = [
 'reservation_id'=>str_repeat('a',32),'owner_id'=>7,'provider_payment_id'=>'pay-123',
 'provider_event_id'=>'approved-event','entry_type'=>'commission_accrual','gross_amount'=>'1000.00',
 'provider_fee_amount'=>'32.50','commission_rate_bps'=>800,'commission_amount'=>'80.00',
 'host_net_estimate'=>'887.50','metadata'=>['source'=>'test'],
];
$id1 = marketplaceRecordLedgerEntry($pdo,$entry);
$id2 = marketplaceRecordLedgerEntry($pdo,$entry);
expectSame($id1,$id2,'Repetição do ledger precisa retornar o registro existente');
$changed = $entry; $changed['commission_amount']='81.00';
expectThrows(fn() => marketplaceRecordLedgerEntry($pdo,$changed),'Mesma chave com valor diferente deve ser bloqueada');
$reversal = $entry;
$reversal['entry_type']='refund_reversal';
$reversal['provider_event_id']='refund-event';
$reversal['gross_amount']='-1000.00';
$reversal['provider_fee_amount']='-32.50';
$reversal['commission_amount']='-80.00';
$reversal['host_net_estimate']='-887.50';
$reversal['reverses_entry_id']=$id1;
$id3 = marketplaceRecordLedgerEntry($pdo,$reversal);
if ($id3 === $id1) throw new RuntimeException('Estorno precisa ser um novo evento append-only.');

echo "Cálculos, idempotência e reversões do ledger passaram.\n";
