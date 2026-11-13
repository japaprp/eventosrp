<?php
declare(strict_types=1);

/** Verify the signed query resource, never an unsigned body resource. */
function webhookPaymentId(string $queryString, string $signatureHeader, string $requestId, string $secret): ?string {
    parse_str($queryString, $query);
    $id=$query['data_id']??null;
    if(!is_string($id)||!ctype_digit($id)||$secret===''||$requestId==='')return null;
    $parts=[];
    foreach(explode(',', $signatureHeader) as $part){
        $pair=explode('=',trim($part),2);
        if(count($pair)!==2)continue;
        [$key,$value]=array_map('trim',$pair);
        if(isset($parts[$key]))return null;
        $parts[$key]=$value;
    }
    $ts=$parts['ts']??'';$signature=$parts['v1']??'';
    if(!ctype_digit($ts)||!preg_match('/^[a-f0-9]{64}$/D',$signature))return null;
    $expected=hash_hmac('sha256','id:'.$id.';request-id:'.$requestId.';ts:'.$ts.';',$secret);
    return hash_equals($expected,$signature)?$id:null;
}

function paymentCents(mixed $value): ?int {
    if(!is_int($value)&&!is_float($value)&&!is_string($value))return null;
    $value=(string)$value;
    if(!preg_match('/^(\d{1,8})(?:\.(\d{1,2}))?$/D',$value,$match))return null;
    return (int)$match[1]*100+(int)str_pad($match[2]??'',2,'0');
}

function paymentMatchesReservation(array $payment,array $reservation,string $paymentId,bool $sandbox): bool {
    $amount=paymentCents($payment['transaction_amount']??null);
    return (string)($payment['id']??'')===$paymentId
        && ($payment['external_reference']??null)===$reservation['id']
        && ($payment['currency_id']??null)==='BRL'
        && ($payment['live_mode']??null)===!$sandbox
        && $amount!==null && $amount>0
        && $amount===paymentCents($reservation['total']);
}

function checkoutUrl(array $preference,bool $sandbox): string {
    $url=$preference[$sandbox?'sandbox_init_point':'init_point']??null;
    $id=$preference['id']??null;
    if(!is_string($url)||!is_string($id)||$id==='')throw new RuntimeException('Checkout incompleto.');
    $parts=parse_url($url);
    if(!$parts||($parts['scheme']??'')!=='https'||isset($parts['user'])||isset($parts['pass'])
        ||isset($parts['port'])&&!in_array($parts['port'],[443],true)
        ||!preg_match('/(^|\.)mercadopago\.(com|com\.br)$/D',$parts['host']??''))throw new RuntimeException('Checkout inválido.');
    return $url;
}

/** Once the request starts, an exception cannot prove that no payable checkout exists. */
function startReservationCheckout(callable $createPreference,callable $savePreference,bool $sandbox): array {
    $preference=$createPreference();
    $url=checkoutUrl($preference,$sandbox);
    $savePreference($preference['id']);
    return ['checkout_url'=>$url];
}

function saveReservationPreference(PDO $pdo,string $reservationId,string $preferenceId): void {
    $query=$pdo->prepare("UPDATE reservations SET preference_id=?,status=CASE WHEN status='creating' THEN 'pending' ELSE status END WHERE id=?");
    $query->execute([$preferenceId,$reservationId]);
}
