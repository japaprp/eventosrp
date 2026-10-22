<?php
declare(strict_types=1);

class CheckoutUnavailableException extends RuntimeException {
    public function __construct(public readonly string $reservationId,public readonly bool $needsReconciliation,Throwable $previous) {
        parent::__construct('Não foi possível abrir o pagamento.',0,$previous);
    }
}

function checkoutAppUrl(string $url): string {
    $url=rtrim($url,'/');$parts=parse_url($url);
    if(!filter_var($url,FILTER_VALIDATE_URL)||!is_array($parts)||($parts['scheme']??'')!=='https'
        ||empty($parts['host'])||isset($parts['user'])||isset($parts['pass'])||isset($parts['query'])||isset($parts['fragment'])) {
        throw new RuntimeException('Configure APP_URL com uma URL HTTPS válida para pagamentos.');
    }
    return $url;
}

/** An uncertain provider response must not release an interval that could still be paid. */
function startReservationCheckout(PDO $pdo,array $reservation,string $email,string $url,bool $sandbox,callable $setupChat,callable $createPreference): array {
    $attempted=false;$id=$reservation['id'];$space=$reservation['space'];$quote=$reservation['quote'];
    try {
        $setupChat();
        $payload=['items'=>[['id'=>(string)$space['id'],'title'=>$space['name'].' — '.$quote['check_in'].' a '.$quote['check_out'].' ('.$quote['days'].' diárias)',
            'quantity'=>1,'currency_id'=>'BRL','unit_price'=>(float)$quote['total']]],
            'external_reference'=>$id,'payer'=>['email'=>$email],'notification_url'=>$url.'/api.php?action=webhook',
            'back_urls'=>['success'=>$url.'/?payment=success','pending'=>$url.'/?payment=pending','failure'=>$url.'/?payment=failure'],'auto_return'=>'approved'];
        $attempted=true;
        $preference=$createPreference($payload);
        $preferenceId=$preference['id']??null;$checkoutUrl=$preference[$sandbox?'sandbox_init_point':'init_point']??null;
        if(!is_string($preferenceId)||!$preferenceId||strlen($preferenceId)>100||!is_string($checkoutUrl)
            ||!filter_var($checkoutUrl,FILTER_VALIDATE_URL)||parse_url($checkoutUrl,PHP_URL_SCHEME)!=='https'
            ||parse_url($checkoutUrl,PHP_URL_USER)!==null) {
            throw new UnexpectedValueException('O provedor não retornou um checkout válido.');
        }
        $query=$pdo->prepare("UPDATE reservations SET preference_id=?,status=IF(status='creating','pending',status) WHERE id=? AND status IN ('creating','pending','approved')");
        $query->execute([$preferenceId,$id]);
        return ['reservation_id'=>$id,'checkout_url'=>$checkoutUrl];
    }catch(Throwable $exception) {
        if(!$attempted) {
            $query=$pdo->prepare("UPDATE reservations SET status='failed' WHERE id=? AND status='creating'");$query->execute([$id]);
        }
        throw new CheckoutUnavailableException($id,$attempted,$exception);
    }
}
