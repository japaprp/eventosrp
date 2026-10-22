<?php
declare(strict_types=1);
require_once __DIR__.'/booking.php';
require_once __DIR__.'/spaces.php';

class ReservationConflictException extends RuntimeException {}

/** Creates the reservation from current database values while holding the space lock. */
function createReservation(PDO $pdo,int $userId,array $input,?DateTimeImmutable $now=null): array {
    $name=$input['contact_name']??null;$phone=$input['phone']??null;
    if(!is_string($name)||mb_strlen(trim($name))<2||mb_strlen($name)>120
        ||!is_string($phone)||strlen($phone)>25
        ||!preg_match('/^(?:55)?[1-9][0-9][0-9]{8,9}$/',preg_replace('/\D/','',$phone))) {
        throw new InvalidArgumentException('Informe seu nome e um celular válido com DDD.');
    }
    $guests=filter_var($input['guests']??null,FILTER_VALIDATE_INT);
    $spaceId=filter_var($input['space_id']??null,FILTER_VALIDATE_INT);
    $message=$input['message']??null;
    if(!$guests||$guests<1||!$spaceId||$spaceId<1||!is_string($message)||!trim($message)||mb_strlen($message)>2000) {
        throw new InvalidArgumentException('Confira os dados do evento.');
    }
    if(($input['terms_accepted']??false)!==true) {
        throw new InvalidArgumentException('Leia e aceite as regras do anfitrião e as condições da locação para continuar.');
    }
    $review=$input['terms_review']??null;
    if(!is_array($review))throw new InvalidArgumentException('Revise as condições e o total no formulário da reserva.');
    foreach(['name','city','neighborhood','rules','total'] as $field) {
        if(!is_string($review[$field]??null))throw new InvalidArgumentException('Revise as condições e o total no formulário da reserva.');
    }
    $pdo->beginTransaction();
    try {
        $query=$pdo->prepare('SELECT * FROM spaces WHERE id=? FOR UPDATE');
        $query->execute([$spaceId]);$space=$query->fetch(PDO::FETCH_ASSOC);
        if(!$space||!$space['active']||!empty($space['is_demo'])||$guests>(int)$space['capacity']) {
            throw new InvalidArgumentException('Espaço indisponível ou capacidade inválida.');
        }
        $plan=$input['plan']??'daily';
        $quote=bookingQuote($input['check_in']??null,$input['check_out']??null,$plan,
            (string)$space['daily_price'],$space['weekend_price']===null?null:(string)$space['weekend_price'],$now);
        enforceSpaceSchedule($space,$quote);
        $expected=['name'=>(string)$space['name'],'city'=>(string)$space['city'],
            'neighborhood'=>(string)$space['neighborhood'],'rules'=>(string)($space['rules']??''),'total'=>$quote['total']];
        foreach($expected as $field=>$value) {
            if($review[$field]!==$value)throw new ReservationConflictException('O anúncio ou o preço mudou. Atualize a página e abra o espaço novamente, revise as condições e aceite antes de reservar.');
        }
        $zone=new DateTimeZone('America/Sao_Paulo');
        $blockedUntil=(new DateTimeImmutable($quote['check_out'],$zone))->modify('+'.(int)$space['cleanup_minutes'].' minutes')->format('Y-m-d H:i:s');
        $query=$pdo->prepare("SELECT id FROM reservations WHERE space_id=? AND check_in < ? AND blocked_until > ? AND status IN ('creating','pending','approved') LIMIT 1");
        $query->execute([$spaceId,$blockedUntil,$quote['check_in']]);
        if($query->fetchColumn())throw new ReservationConflictException('Há uma solicitação ativa em parte deste período. Escolha outra entrada ou saída, ou contate o atendimento.');
        $id=bin2hex(random_bytes(16));
        $acceptedAt=($now??new DateTimeImmutable('now',$zone))->setTimezone($zone)->format('Y-m-d H:i:s');
        $snapshot=reservationTermsSnapshot($space,$quote,$plan,$guests,trim($name),$acceptedAt);
        $query=$pdo->prepare('INSERT INTO reservations(id,user_id,space_id,event_date,check_in,check_out,blocked_until,plan,billable_days,contact_name,guests,phone,message,total,terms_snapshot,terms_accepted_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $query->execute([$id,$userId,$spaceId,$quote['event_date'],$quote['check_in'],$quote['check_out'],$blockedUntil,$plan,$quote['days'],trim($name),$guests,$phone,trim($message),$quote['total'],$snapshot,$acceptedAt]);
        $query=$pdo->prepare('UPDATE users SET name=?,phone=? WHERE id=?');$query->execute([trim($name),$phone,$userId]);
        $pdo->commit();
        return ['id'=>$id,'space'=>$space,'quote'=>$quote,'terms_snapshot'=>$snapshot,'terms_accepted_at'=>$acceptedAt];
    }catch(Throwable $exception) {
        if($pdo->inTransaction())$pdo->rollBack();
        throw $exception;
    }
}
