<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/payments.php';
$count=0;
function check(bool $value,string $label): void {global $count;if(!$value)throw new RuntimeException($label);$count++;}
$secret='test-secret';$id='123456';$request='request-test';$ts='1781009491';
$signature='ts='.$ts.',v1='.hash_hmac('sha256',"id:$id;request-id:$request;ts:$ts;",$secret);
check(webhookPaymentId('action=webhook&data.id='.$id,$signature,$request,$secret)===$id,'Notificação assinada aceita');
foreach([
 ['data.id=999',$signature,$request,$secret],
 ['data.id='.$id,$signature,'outro-request',$secret],
 ['data.id='.$id,$signature,$request,''],
 ['data.id[]=123',$signature,$request,$secret],
 ['id='.$id,$signature,$request,$secret],
 ['data.id='.$id,$signature.',ts=42',$request,$secret],
 ['data.id='.$id,'ts=abc,v1='.str_repeat('0',64),$request,$secret]
] as $args)check(webhookPaymentId(...$args)===null,'Assinatura ou recurso inválido rejeitado');
$reservation=['id'=>str_repeat('a',32),'total'=>'1250.50'];
$payment=['id'=>123456,'external_reference'=>$reservation['id'],'transaction_amount'=>1250.50,'currency_id'=>'BRL','live_mode'=>false];
check(paymentMatchesReservation($payment,$reservation,$id,true),'Pagamento de teste corresponde');
foreach(['id'=>999,'external_reference'=>'outra','transaction_amount'=>1250.49,'currency_id'=>'USD','live_mode'=>true] as $key=>$value){check(!paymentMatchesReservation([...$payment,$key=>$value],$reservation,$id,true),'Inconsistência rejeitada: '.$key);}
check(!paymentMatchesReservation([...$payment,'transaction_amount'=>null],$reservation,$id,true),'Valor ausente rejeitado');
check(!paymentMatchesReservation([...$payment,'transaction_amount'=>true],$reservation,$id,true),'Booleano rejeitado');
check(paymentMatchesReservation([...$payment,'live_mode'=>true],$reservation,$id,false),'Pagamento de produção corresponde');
$preference=['id'=>'preference-test','init_point'=>'https://www.mercadopago.com.br/checkout/v1/redirect','sandbox_init_point'=>'https://sandbox.mercadopago.com.br/checkout/v1/redirect'];
check(checkoutUrl($preference,true)===$preference['sandbox_init_point'],'Seleciona sandbox');
check(checkoutUrl($preference,false)===$preference['init_point'],'Seleciona produção');
foreach(['http://www.mercadopago.com.br/pay','https://mercadopago.com.br.evil.test/pay','https://evil.test/pay','https://user@mercadopago.com.br/pay','https://mercadopago.com.br:123/pay'] as $url){try{checkoutUrl([...$preference,'init_point'=>$url],false);check(false,'Checkout inválido aceito');}catch(RuntimeException){check(true,'URL inválida rejeitada');}}
$pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE reservations(id TEXT PRIMARY KEY,status TEXT,preference_id TEXT)');
$pdo->exec("INSERT INTO reservations VALUES ('r','creating',NULL)");
$save=fn($preferenceId)=>saveReservationPreference($pdo,'r',$preferenceId);
$state=fn()=>$pdo->query("SELECT status FROM reservations WHERE id='r'")->fetchColumn();
$checkout=startReservationCheckout(fn()=>$preference,$save,true);
check($state()==='pending','Checkout válido fica pendente');
check($checkout['checkout_url']===$preference['sandbox_init_point'],'URL devolvida');
$pdo->exec("UPDATE reservations SET status='creating',preference_id=NULL");
try{startReservationCheckout(function(){throw new RuntimeException('timeout após criação remota');},$save,true);}catch(RuntimeException){}
check($state()==='creating','Timeout mantém período em conferência');
try{startReservationCheckout(fn()=>['id'=>'remote-created'],$save,true);}catch(RuntimeException){}
check($state()==='creating','Resposta incompleta mantém período em conferência');
startReservationCheckout(function()use($pdo,$preference){$pdo->exec("UPDATE reservations SET status='approved'");return $preference;},$save,true);
check($state()==='approved','Webhook antecipado não é rebaixado');
check($pdo->query("SELECT preference_id FROM reservations WHERE id='r'")->fetchColumn()==='preference-test','Preferência gravada mesmo após aprovação antecipada');
saveReservationPreference($pdo,'r','preference-test');
check($state()==='approved','Repetição preserva aprovação');
echo "$count verificações de assinatura, pagamento e ciclo do checkout passaram (persistência SQLite).\n";
