<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');
if(!is_file(dirname(__DIR__).'/vendor/autoload.php'))respondUnavailable();
function respondUnavailable(): never { http_response_code(503);echo '{"error":"Servidor em configuração. Instale as dependências PHP e configure as credenciais."}';exit; }
require dirname(__DIR__).'/src/bootstrap.php';
require dirname(__DIR__).'/src/booking.php';
require dirname(__DIR__).'/src/spaces.php';
require dirname(__DIR__).'/src/payment_lifecycle.php';
require dirname(__DIR__).'/src/marketplace_finance.php';
require dirname(__DIR__).'/src/marketplace_oauth.php';
date_default_timezone_set('America/Sao_Paulo');

function paymentLifecycleRecordEvent(PDO $pdo, string $reservationId, array $payment): void {
 $paymentId=(string)($payment['id']??'');$status=(string)($payment['status']??'');
 if($paymentId===''||$status==='')throw new InvalidArgumentException('Evento de pagamento incompleto.');
 $now=(new DateTimeImmutable('now',new DateTimeZone('America/Sao_Paulo')))->format('Y-m-d H:i:s');
 $detail=isset($payment['status_detail'])?substr((string)$payment['status_detail'],0,100):null;
 $q=$pdo->prepare('INSERT INTO reservation_payment_events(reservation_id,payment_id,provider_status,status_detail,amount,currency,event_count,first_seen_at,last_seen_at) VALUES (?,?,?,?,?,?,1,?,?) ON DUPLICATE KEY UPDATE provider_status=VALUES(provider_status),status_detail=VALUES(status_detail),amount=VALUES(amount),currency=VALUES(currency),event_count=event_count+1,last_seen_at=VALUES(last_seen_at)');
 $q->execute([$reservationId,$paymentId,$status,$detail,number_format((float)($payment['transaction_amount']??0),2,'.',''),(string)($payment['currency_id']??''),$now,$now]);
}
function paymentLifecycleApplyVerifiedPayment(PDO $pdo, string $reservationId, array $payment): void {
 $paymentId=(string)($payment['id']??'');$status=(string)($payment['status']??'');
 if($paymentId===''||$status===''||(string)($payment['external_reference']??'')!==$reservationId)throw new InvalidArgumentException('Referência do pagamento inconsistente.');
 $initial=$pdo->prepare('SELECT space_id FROM reservations WHERE id=?');$initial->execute([$reservationId]);$spaceId=$initial->fetchColumn();
 if(!$spaceId)return;
 $pdo->beginTransaction();
 try{
  $lock=$pdo->prepare('SELECT id FROM spaces WHERE id=? FOR UPDATE');$lock->execute([$spaceId]);
  $q=$pdo->prepare('SELECT * FROM reservations WHERE id=? FOR UPDATE');$q->execute([$reservationId]);$reservation=$q->fetch(PDO::FETCH_ASSOC);
  if(!$reservation){$pdo->commit();return;}
  if(($payment['currency_id']??'')!=='BRL'||abs((float)($payment['transaction_amount']??-1)-(float)$reservation['total'])>0.001)throw new InvalidArgumentException('Valor ou moeda do pagamento inconsistente.');
  paymentLifecycleRecordEvent($pdo,$reservationId,$payment);
  $existingId=isset($reservation['payment_id'])?(string)$reservation['payment_id']:null;
  $conflict=false;
  if($status==='approved'&&$reservation['status']==='pending'){
   $q=$pdo->prepare("SELECT id FROM reservations WHERE space_id=? AND id<>? AND check_in < ? AND blocked_until > ? AND status IN ('creating','pending','approved') LIMIT 1");
   $q->execute([$reservation['space_id'],$reservationId,$reservation['blocked_until'],$reservation['check_in']]);$conflict=(bool)$q->fetchColumn();
  }
  $decision=($status==='approved'&&$reservation['status']==='pending'&&$reservation['payment_attention']!=='none')?'refund_required':paymentLifecycleDecision((string)$reservation['status'],$status,$existingId,$paymentId,$conflict);
  $detail=isset($payment['status_detail'])?substr((string)$payment['status_detail'],0,100):null;
  $now=(new DateTimeImmutable('now',new DateTimeZone('America/Sao_Paulo')))->format('Y-m-d H:i:s');
  if($decision==='approve'){
   $q=$pdo->prepare("UPDATE reservations SET status='approved',payment_id=?,payment_status=?,payment_status_detail=?,payment_updated_at=? WHERE id=? AND status='pending'");
   $q->execute([$paymentId,$status,$detail,$now,$reservationId]);
  }elseif($decision==='idempotent_approval'){
   $q=$pdo->prepare("UPDATE reservations SET payment_status='approved',payment_status_detail=?,payment_updated_at=? WHERE id=? AND status='approved' AND payment_id=?");
   $q->execute([$detail,$now,$reservationId,$paymentId]);
  }elseif($decision==='refund_required'){
   if($reservation['status']==='pending'&&$status==='approved'&&$conflict){
    $q=$pdo->prepare("UPDATE reservations SET status='failed',payment_status='approved',payment_status_detail=?,payment_updated_at=?,payment_attention='refund_required' WHERE id=? AND status='pending'");
    $q->execute([$detail,$now,$reservationId]);
   }elseif(in_array($reservation['status'],['expired','failed','cancelled','creating'],true)){
    $q=$pdo->prepare("UPDATE reservations SET payment_status=?,payment_status_detail=?,payment_updated_at=?,payment_attention='refund_required' WHERE id=?");
    $q->execute([$status,$detail,$now,$reservationId]);
   }else{
    $q=$pdo->prepare("UPDATE reservations SET payment_attention='refund_required',payment_updated_at=? WHERE id=?");
    $q->execute([$now,$reservationId]);
   }
  }elseif($decision==='chargeback_review'||$decision==='refund_review'){
   $attention=$decision==='chargeback_review'?'chargeback':'review_required';
   if($existingId===null||$existingId===$paymentId){
    $q=$pdo->prepare('UPDATE reservations SET payment_status=?,payment_status_detail=?,payment_updated_at=?,payment_attention=? WHERE id=?');
    $q->execute([$status,$detail,$now,$attention,$reservationId]);
   }else{
    $q=$pdo->prepare('UPDATE reservations SET payment_attention=?,payment_updated_at=? WHERE id=?');
    $q->execute([$attention,$now,$reservationId]);
   }
  }elseif($decision==='preserve_approved_booking'){
   if($existingId===$paymentId){
    $attention=in_array($status,['refunded','charged_back'],true)?($status==='charged_back'?'chargeback':'review_required'):'none';
    $q=$pdo->prepare('UPDATE reservations SET payment_status=?,payment_status_detail=?,payment_updated_at=?,payment_attention=? WHERE id=?');
    $q->execute([$status,$detail,$now,$attention,$reservationId]);
   }
  }elseif(in_array($decision,['keep_hold','keep_until_preference_expiry'],true)){
   if($reservation['status']==='pending'&&($existingId===null||$existingId===$paymentId)){
    $q=$pdo->prepare('UPDATE reservations SET payment_status=?,payment_status_detail=?,payment_updated_at=? WHERE id=? AND status=\'pending\'');
    $q->execute([$status,$detail,$now,$reservationId]);
   }
  }
  $pdo->commit();
 }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
function paymentLifecycleReconcileExpiredHold(PDO $pdo, array $reservation): void {
 if(($reservation['status']??'')!=='pending'||empty($reservation['hold_expires_at']))return;
 $now=new DateTimeImmutable('now',new DateTimeZone('America/Sao_Paulo'));
 try{$holdDeadline=new DateTimeImmutable((string)$reservation['hold_expires_at'],new DateTimeZone('America/Sao_Paulo'));}catch(Throwable){return;}
 if($holdDeadline>$now)return;
 $reservationId=(string)$reservation['id'];
 try{
  $search=mp('/v1/payments/search?external_reference='.rawurlencode($reservationId).'&sort=date_created&criteria=desc&limit=100');
  $payments=is_array($search['results']??null)?$search['results']:[];
  foreach($payments as $payment){
   if(!is_array($payment)||(string)($payment['external_reference']??'')!==$reservationId)continue;
   paymentLifecycleApplyVerifiedPayment($pdo,$reservationId,$payment);
  }
  $q=$pdo->prepare('SELECT * FROM reservations WHERE id=?');$q->execute([$reservationId]);$fresh=$q->fetch(PDO::FETCH_ASSOC);
  if(!$fresh||$fresh['status']!=='pending'||$fresh['payment_attention']!=='none')return;
  $complete=isset($search['paging']['total'])&&(int)$search['paging']['total']<=count($payments);
  if(!$complete||empty($fresh['preference_id']))return;
  $preference=mp('/checkout/preferences/'.rawurlencode((string)$fresh['preference_id']));
  if(($preference['expires']??false)!==true||empty($preference['expiration_date_to']))return;
  try{$preferenceDeadline=new DateTimeImmutable((string)$preference['expiration_date_to']);}catch(Throwable){return;}
  if($preferenceDeadline>$now)return;
  if(!paymentLifecycleCanReleaseHold('pending',(string)$fresh['hold_expires_at'],true,$payments,$now))return;
  $q=$pdo->prepare("UPDATE reservations SET status='expired' WHERE id=? AND status='pending' AND payment_attention='none' AND hold_expires_at<=?");
  $q->execute([$reservationId,$now->format('Y-m-d H:i:s')]);
 }catch(Throwable $e){error_log('Encontro payment reconciliation: '.get_class($e));}
}
function paymentLifecycleReconcileOverlappingHolds(PDO $pdo, int $spaceId, string $checkIn, string $blockedUntil): void {
 $now=(new DateTimeImmutable('now',new DateTimeZone('America/Sao_Paulo')))->format('Y-m-d H:i:s');
 $q=$pdo->prepare("SELECT * FROM reservations WHERE space_id=? AND check_in < ? AND blocked_until > ? AND status='pending' AND hold_expires_at IS NOT NULL AND hold_expires_at<=? ORDER BY created_at ASC LIMIT 2");
 $q->execute([$spaceId,$blockedUntil,$checkIn,$now]);
 foreach($q->fetchAll(PDO::FETCH_ASSOC) as $reservation)paymentLifecycleReconcileExpiredHold($pdo,$reservation);
}

try {
 $action=$_GET['action']??'';$method=$_SERVER['REQUEST_METHOD'];
 if($action==='spaces'&&$method==='GET'){$rows=database()->query('SELECT * FROM spaces WHERE active=1 ORDER BY id DESC')->fetchAll(PDO::FETCH_ASSOC);respond(array_map('publicSpace',$rows));}
 if($action==='webhook') {
  if($method!=='POST')respond(['error'=>'Método não permitido.'],405);
  parse_str($_SERVER['QUERY_STRING']??'',$query);$id=(string)($query['data_id']??'');
  $parts=[];foreach(explode(',',$_SERVER['HTTP_X_SIGNATURE']??'') as $part){$pair=explode('=',trim($part),2);if(count($pair)===2)$parts[$pair[0]]=$pair[1];}
  $ts=$parts['ts']??'';$signature=$parts['v1']??'';$request=$_SERVER['HTTP_X_REQUEST_ID']??'';
  $manifest='id:'.strtolower($id).';request-id:'.$request.';ts:'.$ts.';';
  if(!env('MP_WEBHOOK_SECRET')||!ctype_digit($id)||!ctype_digit($ts)||!$request||!hash_equals(hash_hmac('sha256',$manifest,env('MP_WEBHOOK_SECRET')),$signature))respond(['error'=>'Assinatura inválida.'],401);
  $payment=mp('/v1/payments/'.rawurlencode($id));$reference=(string)($payment['external_reference']??'');
  if((string)($payment['id']??'')!==$id)respond(['error'=>'Identificador do pagamento inconsistente.'],400);
  $pdo=database();$q=$pdo->prepare('SELECT id FROM reservations WHERE id=?');$q->execute([$reference]);
  if(!$q->fetchColumn())respond(['received'=>true]);
  try{paymentLifecycleApplyVerifiedPayment($pdo,$reference,$payment);}catch(InvalidArgumentException $e){respond(['error'=>'Pagamento inconsistente.'],400);}
  respond(['received'=>true]);
 }
 if($action==='mp-oauth-callback') {
  if($method!=='GET')respond(['error'=>'Método não permitido.'],405);
  $appUrl=rtrim(env('APP_URL'),'/');
  if(!str_starts_with($appUrl,'https://'))respond(['error'=>'Callback OAuth exige APP_URL com HTTPS.'],503);
  try {
   if(isset($_GET['error'])) {
    if(isset($_GET['state']))marketplaceConsumeOAuthState(database(),(string)$_GET['state']);
    header('Location: '.$appUrl.'/?mp_connect=denied',true,302);exit;
   }
   marketplaceOAuthComplete(database(),(string)($_GET['code']??''),(string)($_GET['state']??''));
   header('Location: '.$appUrl.'/?mp_connect=success',true,302);exit;
  } catch(Throwable $e) {
   error_log('Encontro marketplace OAuth callback: '.get_class($e));
   header('Location: '.$appUrl.'/?mp_connect=error',true,302);exit;
  }
 }
 if(!in_array($action,['reservations','reserve','profile','my-spaces','save-space','host-reservations','mp-connect','mp-account','mp-disconnect'],true))respond(['error'=>'Rota não encontrada.'],404);
 if((in_array($action,['my-spaces','host-reservations','mp-connect','mp-account'],true)&&$method!=='GET')||($action==='mp-disconnect'&&$method!=='POST')||($action==='save-space'&&$method!=='POST'))respond(['error'=>'Método não permitido.'],405);
 if(($action==='reserve'&&$method!=='POST')||($action==='reservations'&&$method!=='GET')||($action==='profile'&&!in_array($method,['GET','POST'],true)))respond(['error'=>'Método não permitido.'],405);
 $header=$_SERVER['HTTP_AUTHORIZATION']??'';if(!preg_match('/^Bearer (.+)$/',$header,$match))respond(['error'=>'Entre na sua conta.'],401);
 try{$token=firebase()->createAuth()->verifyIdToken($match[1],true);}catch(Throwable $e){respond(['error'=>'Sessão inválida. Entre novamente.'],401);}
 $uid=$token->claims()->get('sub');$email=$token->claims()->get('email','');$pdo=database();
 $q=$pdo->prepare('INSERT INTO users(firebase_uid,email) VALUES (?,?) ON DUPLICATE KEY UPDATE email=VALUES(email)');$q->execute([$uid,$email]);
 $q=$pdo->prepare('SELECT id FROM users WHERE firebase_uid=?');$q->execute([$uid]);$userId=$q->fetchColumn();
 if($action==='mp-account')respond(marketplaceSellerAccountSummary($pdo,(int)$userId));
 if($action==='mp-disconnect'){marketplaceDisconnectSellerAccount($pdo,(int)$userId);respond(['status'=>'disconnected']);}
 if($action==='mp-connect'){
  try{respond(['authorization_url'=>marketplaceOAuthStart($pdo,(int)$userId)]);}
  catch(DomainException $e){respond(['error'=>$e->getMessage()],403);}
  catch(Throwable $e){error_log('Encontro marketplace OAuth start: '.get_class($e));respond(['error'=>'Conexão do Mercado Pago ainda não configurada.'],503);}
 }
 if($action==='my-spaces'){$q=$pdo->prepare('SELECT * FROM spaces WHERE owner_id=? ORDER BY id DESC');$q->execute([$userId]);respond(array_map('publicSpace',$q->fetchAll(PDO::FETCH_ASSOC)));}
 if($action==='host-reservations'){$q=$pdo->prepare('SELECT r.id,r.check_in,r.check_out,r.plan,r.billable_days,r.total,r.status,r.payment_status,r.payment_attention,r.contact_name,r.guests,s.name FROM reservations r JOIN spaces s ON s.id=r.space_id WHERE s.owner_id=? ORDER BY r.created_at DESC');$q->execute([$userId]);respond($q->fetchAll(PDO::FETCH_ASSOC));}
 if($action==='save-space'){
  if((int)($_SERVER['CONTENT_LENGTH']??0)>40000)respond(['error'=>'Solicitação muito grande.'],413);
  $input=json_decode(file_get_contents('php://input'),true,512,JSON_THROW_ON_ERROR);if(!is_array($input))respond(['error'=>'Dados inválidos.'],400);
  try{$data=validateSpace($input);}catch(InvalidArgumentException $e){respond(['error'=>$e->getMessage()],422);}
  $id=$input['id']??null;$pdo->beginTransaction();
  if($id!==null){
   if(!filter_var($id,FILTER_VALIDATE_INT)||$id<1){$pdo->rollBack();respond(['error'=>'Espaço inválido.'],422);}
   $q=$pdo->prepare('SELECT id FROM spaces WHERE id=? AND owner_id=? FOR UPDATE');$q->execute([$id,$userId]);
   if(!$q->fetchColumn()){$pdo->rollBack();respond(['error'=>'Espaço não encontrado para esta conta.'],404);}
   $sets=implode(',',array_map(fn($key)=>$key.'=?',array_keys($data)));$q=$pdo->prepare('UPDATE spaces SET '.$sets.' WHERE id=? AND owner_id=?');$q->execute([...array_values($data),$id,$userId]);
  }else{
   $data['owner_id']=$userId;$data['is_demo']=0;$columns=implode(',',array_keys($data));$marks=implode(',',array_fill(0,count($data),'?'));
   $q=$pdo->prepare('INSERT INTO spaces ('.$columns.') VALUES ('.$marks.')');$q->execute(array_values($data));$id=(int)$pdo->lastInsertId();
  }
  $pdo->commit();$q=$pdo->prepare('SELECT * FROM spaces WHERE id=? AND owner_id=?');$q->execute([$id,$userId]);respond(publicSpace($q->fetch(PDO::FETCH_ASSOC)),201);
 }
 if($action==='reservations'){$q=$pdo->prepare('SELECT r.id,r.event_date,r.check_in,r.check_out,r.plan,r.billable_days,r.total,r.status,r.payment_status,r.payment_attention,s.name FROM reservations r JOIN spaces s ON s.id=r.space_id WHERE r.user_id=? ORDER BY r.created_at DESC');$q->execute([$userId]);respond($q->fetchAll(PDO::FETCH_ASSOC));}
 if($action==='profile'&&$method==='GET'){$q=$pdo->prepare('SELECT name,phone FROM users WHERE id=?');$q->execute([$userId]);respond($q->fetch(PDO::FETCH_ASSOC));}
 if($action==='reserve'&&(!env('MP_ACCESS_TOKEN')||!env('MP_WEBHOOK_SECRET')))respond(['error'=>'Pagamentos ainda não configurados.'],503);
 if((int)($_SERVER['CONTENT_LENGTH']??0)>12000)respond(['error'=>'Solicitação muito grande.'],413);
 $input=json_decode(file_get_contents('php://input'),true,512,JSON_THROW_ON_ERROR);
 if(!is_array($input))respond(['error'=>'Dados inválidos.'],400);
 $contactName=$input[$action==='profile'?'name':'contact_name']??'';
 $phone=$input['phone']??'';
 if(!is_string($contactName)||strlen(trim($contactName))<2||strlen($contactName)>480||mb_strlen($contactName)>120||!is_string($phone)||strlen($phone)>25||!preg_match('/^(?:55)?[1-9][0-9][0-9]{8,9}$/',preg_replace('/\D/','',$phone)))respond(['error'=>'Informe seu nome e um celular válido com DDD.'],422);
 if($action==='profile'){$q=$pdo->prepare('UPDATE users SET name=?,phone=? WHERE id=?');$q->execute([trim($contactName),$phone,$userId]);respond(['name'=>trim($contactName),'phone'=>$phone]);}
 $guests=filter_var($input['guests']??null,FILTER_VALIDATE_INT);$spaceId=filter_var($input['space_id']??null,FILTER_VALIDATE_INT);
 $message=$input['message']??'';
 if(!$guests||$guests<1||!$spaceId||!is_string($message)||!trim($message)||mb_strlen($message)>2000)respond(['error'=>'Confira os dados do evento.'],422);
 if(($input['terms_accepted']??false)!==true)respond(['error'=>'Confirme o aceite dos termos e das regras do espaço para continuar.'],422);
 $preflight=$pdo->prepare('SELECT * FROM spaces WHERE id=?');$preflight->execute([$spaceId]);$preSpace=$preflight->fetch(PDO::FETCH_ASSOC);
 if(!$preSpace||!$preSpace['active']||$guests>(int)$preSpace['capacity'])respond(['error'=>'Espaço indisponível ou capacidade inválida.'],422);
 $plan=$input['plan']??'daily';
 try{$preQuote=bookingQuote($input['check_in']??null,$input['check_out']??null,$plan,(string)$preSpace['daily_price'],$preSpace['weekend_price']===null?null:(string)$preSpace['weekend_price']);enforceSpaceSchedule($preSpace,$preQuote);}
 catch(InvalidArgumentException $e){respond(['error'=>$e->getMessage()],422);}
 $preBlockedUntil=(new DateTimeImmutable($preQuote['check_out'],new DateTimeZone('America/Sao_Paulo')))->modify('+'.(int)$preSpace['cleanup_minutes'].' minutes')->format('Y-m-d H:i:s');
 paymentLifecycleReconcileOverlappingHolds($pdo,(int)$spaceId,$preQuote['check_in'],$preBlockedUntil);
 $pdo->beginTransaction();$q=$pdo->prepare('SELECT * FROM spaces WHERE id=? FOR UPDATE');$q->execute([$spaceId]);$space=$q->fetch(PDO::FETCH_ASSOC);
 if(!$space||!$space['active']||$guests>$space['capacity']){$pdo->rollBack();respond(['error'=>'Espaço indisponível ou capacidade inválida.'],422);}
 try{$quote=bookingQuote($input['check_in']??null,$input['check_out']??null,$plan,(string)$space['daily_price'],$space['weekend_price']===null?null:(string)$space['weekend_price']);enforceSpaceSchedule($space,$quote);}
 catch(InvalidArgumentException $e){$pdo->rollBack();respond(['error'=>$e->getMessage()],422);}
 $blockedUntil=(new DateTimeImmutable($quote['check_out'],new DateTimeZone('America/Sao_Paulo')))->modify('+'.(int)$space['cleanup_minutes'].' minutes')->format('Y-m-d H:i:s');
 $q=$pdo->prepare("SELECT id FROM reservations WHERE space_id=? AND check_in < ? AND blocked_until > ? AND status IN ('creating','pending','approved') LIMIT 1");$q->execute([$spaceId,$blockedUntil,$quote['check_in']]);
 if($q->fetchColumn()){$pdo->rollBack();respond(['error'=>'Há uma solicitação ativa em parte deste período. Escolha outra entrada ou saída, ou contate o atendimento.'],409);}
 $id=bin2hex(random_bytes(16));
 $acceptedAt=(new DateTimeImmutable('now',new DateTimeZone('America/Sao_Paulo')))->format('Y-m-d H:i:s');
 $agreementSnapshot=reservationAgreementSnapshot($space,$quote,['plan'=>$plan,'contact_name'=>$contactName,'phone'=>$phone,'guests'=>$guests,'message'=>$message],RESERVATION_TERMS_VERSION);
 $q=$pdo->prepare('INSERT INTO reservations(id,user_id,space_id,event_date,check_in,check_out,blocked_until,plan,billable_days,contact_name,guests,phone,message,terms_version,terms_accepted_at,agreement_snapshot,total) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
 $q->execute([$id,$userId,$spaceId,$quote['event_date'],$quote['check_in'],$quote['check_out'],$blockedUntil,$plan,$quote['days'],trim($contactName),$guests,$phone,trim($message),RESERVATION_TERMS_VERSION,$acceptedAt,$agreementSnapshot,$quote['total']]);
 $q=$pdo->prepare('UPDATE users SET name=?,phone=? WHERE id=?');$q->execute([trim($contactName),$phone,$userId]);$pdo->commit();
 try {
  firebase()->createDatabase()->getReference('chats/'.$id.'/members/'.$uid)->set(true);
  if($space['owner_id']){$q=$pdo->prepare('SELECT firebase_uid FROM users WHERE id=?');$q->execute([$space['owner_id']]);$ownerUid=$q->fetchColumn();if($ownerUid)firebase()->createDatabase()->getReference('chats/'.$id.'/members/'.$ownerUid)->set(true);}
  $url=rtrim(env('APP_URL'),'/');if(!str_starts_with($url,'https://'))throw new RuntimeException('Configure APP_URL com HTTPS para pagamentos.');
  $preferenceStartsAt=new DateTimeImmutable('now',new DateTimeZone('America/Sao_Paulo'));
  $preferenceExpiresAt=$preferenceStartsAt->modify('+3 days');
  $holdExpiresAt=$preferenceExpiresAt->modify('+6 hours');
  $preference=mp('/checkout/preferences',['items'=>[['id'=>(string)$spaceId,'title'=>$space['name'].' — '.$quote['check_in'].' a '.$quote['check_out'].' ('.$quote['days'].' diárias)','quantity'=>1,'currency_id'=>'BRL','unit_price'=>(float)$quote['total']]],'external_reference'=>$id,'payer'=>['email'=>$email],'notification_url'=>$url.'/api.php?action=webhook','back_urls'=>['success'=>$url.'/?payment=success','pending'=>$url.'/?payment=pending','failure'=>$url.'/?payment=failure'],'auto_return'=>'approved','expires'=>true,'expiration_date_from'=>$preferenceStartsAt->format('Y-m-d\\TH:i:s.vP'),'expiration_date_to'=>$preferenceExpiresAt->format('Y-m-d\\TH:i:s.vP'),'date_of_expiration'=>$preferenceExpiresAt->format('Y-m-d\\TH:i:s.vP')]);
  $q=$pdo->prepare("UPDATE reservations SET status='pending',preference_id=?,hold_expires_at=? WHERE id=? AND status='creating'");$q->execute([$preference['id'],$holdExpiresAt->format('Y-m-d H:i:s'),$id]);
  respond(['reservation_id'=>$id,'checkout_url'=>$preference[env('MP_SANDBOX','true')==='true'?'sandbox_init_point':'init_point']],201);
 }catch(Throwable $e){$q=$pdo->prepare("UPDATE reservations SET status='failed' WHERE id=? AND status='creating'");$q->execute([$id]);throw $e;}
}catch(JsonException $e){respond(['error'=>'Dados inválidos.'],400);}catch(PDOException $e){if(isset($pdo)&&$pdo->inTransaction())$pdo->rollBack();$info=$e->errorInfo;$driverCode=is_array($info)&&isset($info[1])?(string)$info[1]:'n/a';$message=preg_replace('/((?:password|passwd|pwd)\\s*[=:]\\s*)[^;\\s]*/i','$1[redacted]',$e->getMessage())??'diagnostic unavailable';error_log('Encontro API: PDOException SQLSTATE='.$e->getCode().' driver='.$driverCode.' message='.$message);respond(['error'=>'Serviço temporariamente indisponível. Verifique a configuração ou tente novamente.'],503);}catch(Throwable $e){if(isset($pdo)&&$pdo->inTransaction())$pdo->rollBack();error_log('Encontro API: '.get_class($e));respond(['error'=>'Serviço temporariamente indisponível. Verifique a configuração ou tente novamente.'],503);}
