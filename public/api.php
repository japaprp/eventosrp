<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');
if(!is_file(dirname(__DIR__).'/vendor/autoload.php'))respondUnavailable();
function respondUnavailable(): never { http_response_code(503);echo '{"error":"Servidor em configuração. Instale as dependências PHP e configure as credenciais."}';exit; }
require dirname(__DIR__).'/src/bootstrap.php';
require dirname(__DIR__).'/src/account_consent.php';
require dirname(__DIR__).'/src/booking.php';
require dirname(__DIR__).'/src/spaces.php';
require dirname(__DIR__).'/src/payment_lifecycle.php';
require dirname(__DIR__).'/src/payments.php';
date_default_timezone_set('America/Sao_Paulo');

function requestIsSecure(): bool {
 $forwarded=strtolower(trim(explode(',',(string)($_SERVER['HTTP_X_FORWARDED_PROTO']??''))[0]));
 if($forwarded==='https'||(!empty($_SERVER['HTTPS'])&&strtolower((string)$_SERVER['HTTPS'])!=='off'))return true;
 $host=strtolower((string)($_SERVER['HTTP_HOST']??''));
 $host=preg_replace('/:\d+$/','',$host)??$host;
 return in_array($host,['localhost','127.0.0.1','::1'],true);
}

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
   $q=$pdo->prepare("SELECT id FROM reservations WHERE space_id=? AND id<>? AND check_in < ? AND blocked_until > ? AND status IN ('creating','requested','confirmed','pending','approved') LIMIT 1");
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
 $reservationId=(string)($reservation['id']??'');
 if($reservationId==='')return;
 paymentLifecycleWithAdvisoryLock($pdo,$reservationId,function()use($pdo,$reservation):void{
  paymentLifecycleReconcileExpiredHoldUnlocked($pdo,$reservation);
 });
}

function paymentLifecycleReconcileExpiredHoldUnlocked(PDO $pdo, array $reservation): void {
 if(($reservation['status']??'')!=='pending'||empty($reservation['hold_expires_at']))return;
 $now=new DateTimeImmutable('now',new DateTimeZone('America/Sao_Paulo'));
 try{$holdDeadline=new DateTimeImmutable((string)$reservation['hold_expires_at'],new DateTimeZone('America/Sao_Paulo'));}catch(Throwable){return;}
 if($holdDeadline>$now)return;
 $reservationId=(string)$reservation['id'];
 try{
  $search=mp('/v1/payments/search?external_reference='.rawurlencode($reservationId).'&sort=date_created&criteria=desc&limit=100');
  $payments=is_array($search['results']??null)?$search['results']:[];
  $verifiedPayments=[];$allPaymentsVerified=true;
  foreach($payments as $payment){
   if(!is_array($payment)||(string)($payment['external_reference']??'')!==$reservationId){$allPaymentsVerified=false;continue;}
   if(!paymentMatchesReservation($payment,$reservation,(string)($payment['id']??''),env('MP_SANDBOX','true')==='true')){$allPaymentsVerified=false;continue;}
   $verifiedPayments[]=$payment;
   paymentLifecycleApplyVerifiedPayment($pdo,$reservationId,$payment);
  }
  $q=$pdo->prepare('SELECT * FROM reservations WHERE id=?');$q->execute([$reservationId]);$fresh=$q->fetch(PDO::FETCH_ASSOC);
  if(!$fresh||$fresh['status']!=='pending'||$fresh['payment_attention']!=='none')return;
  $complete=$allPaymentsVerified&&isset($search['paging']['total'])&&(int)$search['paging']['total']<=count($payments);
  if(!$complete||empty($fresh['preference_id']))return;
  $preference=mp('/checkout/preferences/'.rawurlencode((string)$fresh['preference_id']));
  if(($preference['expires']??false)!==true||empty($preference['expiration_date_to']))return;
  try{$preferenceDeadline=new DateTimeImmutable((string)$preference['expiration_date_to']);}catch(Throwable){return;}
  if($preferenceDeadline>$now)return;
  if(!paymentLifecycleCanReleaseHold('pending',(string)$fresh['hold_expires_at'],true,$verifiedPayments,$now))return;
  paymentLifecycleExpirePendingReservation($pdo,$reservationId,$now);
 }catch(Throwable $e){error_log('Encontro payment reconciliation: '.get_class($e));}
}

function paymentLifecycleReconcileOverlappingHolds(PDO $pdo, int $spaceId, string $checkIn, string $blockedUntil): void {
 $now=(new DateTimeImmutable('now',new DateTimeZone('America/Sao_Paulo')))->format('Y-m-d H:i:s');
 $q=$pdo->prepare("SELECT * FROM reservations WHERE space_id=? AND check_in < ? AND blocked_until > ? AND (
  (status='pending' AND hold_expires_at IS NOT NULL AND hold_expires_at<=?)
  OR (status='creating' AND payment_attention='none' AND preference_id IS NULL AND payment_id IS NULL AND TIMESTAMPDIFF(MINUTE,created_at,CURRENT_TIMESTAMP)>=15)
 ) ORDER BY created_at ASC LIMIT 5");
 $q->execute([$spaceId,$blockedUntil,$checkIn,$now]);
 foreach($q->fetchAll(PDO::FETCH_ASSOC) as $reservation){
  if($reservation['status']==='creating')paymentLifecycleExpireStaleCreatingReservation($pdo,(string)$reservation['id'],15);
  else paymentLifecycleReconcileExpiredHold($pdo,$reservation);
 }
}

try {
 $action=$_GET['action']??'';$method=$_SERVER['REQUEST_METHOD'];
 if($action==='spaces'&&$method==='GET'){$rows=database()->query("SELECT * FROM spaces WHERE active=1 AND is_demo=0 AND (owner_id IS NULL OR owner_id IN (SELECT id FROM users WHERE owner_status='approved')) ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);respond(array_map('publicSpace',$rows));}
 if($action==='webhook') {
  if(env('RESERVATIONS_PAYMENT_MODE','request')!=='payment')respond(['error'=>'Pagamentos estão desativados.'],503);
  if(!requestIsSecure())respond(['error'=>'HTTPS obrigatório.'],400);
  if($method!=='POST')respond(['error'=>'Método não permitido.'],405);
  $id=webhookPaymentId($_SERVER['QUERY_STRING']??'',$_SERVER['HTTP_X_SIGNATURE']??'',$_SERVER['HTTP_X_REQUEST_ID']??'',env('MP_WEBHOOK_SECRET'));
  if($id===null)respond(['error'=>'Assinatura inválida.'],401);
  $payment=mp('/v1/payments/'.rawurlencode($id));
  $reference=(string)($payment['external_reference']??'');
  $pdo=database();$q=$pdo->prepare('SELECT * FROM reservations WHERE id=?');$q->execute([$reference]);$reservation=$q->fetch(PDO::FETCH_ASSOC);
  if(!$reservation)respond(['received'=>true]);
  if(!paymentMatchesReservation($payment,$reservation,$id,env('MP_SANDBOX','true')==='true'))respond(['error'=>'Pagamento inconsistente.'],400);
  try{paymentLifecycleApplyVerifiedPayment($pdo,$reference,$payment);}catch(InvalidArgumentException $e){respond(['error'=>'Pagamento inconsistente.'],400);}
  respond(['received'=>true]);
 }
 if($action==='reconcile-expired-holds') {
  if(!requestIsSecure())respond(['error'=>'HTTPS obrigatório.'],400);
  if($method!=='POST')respond(['error'=>'Método não permitido.'],405);
  if(env('RESERVATIONS_PAYMENT_MODE','request')!=='payment')respond(['disabled'=>true,'reason'=>'Pagamentos estão desativados.'],503);
  $configuredSecret=env('RESERVATION_RECONCILE_SECRET');
  $providedHeader=$_SERVER['HTTP_AUTHORIZATION']??'';
  if(strlen($configuredSecret)<32)respond(['error'=>'Reconciliação agendada não configurada.'],503);
  if(!preg_match('/^Bearer (.+)$/',$providedHeader,$secretMatch)||!hash_equals($configuredSecret,$secretMatch[1]))respond(['error'=>'Não autorizado.'],401);
  $pdo=database();
  $now=(new DateTimeImmutable('now',new DateTimeZone('America/Sao_Paulo')))->format('Y-m-d H:i:s');
  $q=$pdo->prepare("SELECT * FROM reservations WHERE
   (status='pending' AND hold_expires_at IS NOT NULL AND hold_expires_at<=?)
   OR (status='creating' AND payment_attention='none' AND preference_id IS NULL AND payment_id IS NULL AND TIMESTAMPDIFF(MINUTE,created_at,CURRENT_TIMESTAMP)>=15)
   ORDER BY created_at ASC LIMIT 5");
  $q->execute([$now]);$due=$q->fetchAll(PDO::FETCH_ASSOC);
  foreach($due as $reservation){
   if($reservation['status']==='creating')paymentLifecycleExpireStaleCreatingReservation($pdo,(string)$reservation['id'],15);
   else paymentLifecycleReconcileExpiredHold($pdo,$reservation);
  }
  $remaining=$pdo->prepare("SELECT COUNT(*) FROM reservations WHERE
   (status='pending' AND hold_expires_at IS NOT NULL AND hold_expires_at<=?)
   OR (status='creating' AND payment_attention='none' AND preference_id IS NULL AND payment_id IS NULL AND TIMESTAMPDIFF(MINUTE,created_at,CURRENT_TIMESTAMP)>=15)");
  $remaining->execute([$now]);
  respond(['received'=>true,'attempted'=>count($due),'remaining_due'=>(int)$remaining->fetchColumn()]);
 }
 if(!in_array($action,['reservations','reserve','profile','my-spaces','save-space','host-reservations','upload-space-photo','delete-space-photo','chat-access','owner-requests','review-owner','review-reservation','account-consent-requirements','account-consent-status','account-consent'],true))respond(['error'=>'Rota não encontrada.'],404);
 if((in_array($action,['my-spaces','host-reservations','owner-requests','account-consent-requirements','account-consent-status'],true)&&$method!=='GET')||(in_array($action,['save-space','upload-space-photo','delete-space-photo','chat-access','review-owner','review-reservation','account-consent'],true)&&$method!=='POST'))respond(['error'=>'Método não permitido.'],405);
 if(($action==='reserve'&&$method!=='POST')||($action==='reservations'&&$method!=='GET')||($action==='profile'&&!in_array($method,['GET','POST'],true)))respond(['error'=>'Método não permitido.'],405);
 $header=$_SERVER['HTTP_AUTHORIZATION']??'';if(!preg_match('/^Bearer (.+)$/',$header,$match))respond(['error'=>'Entre na sua conta.'],401);
 try{$token=firebase()->createAuth()->verifyIdToken($match[1],true);}catch(Throwable $e){respond(['error'=>'Sessão inválida. Entre novamente.'],401);}
 $uid=$token->claims()->get('sub');$email=$token->claims()->get('email','');
 if($action==='upload-space-photo'){
  if(!requestIsSecure())respond(['error'=>'HTTPS obrigatório.'],400);
  if((int)($_SERVER['CONTENT_LENGTH']??0)>3*1024*1024)respond(['error'=>'Solicitação muito grande.'],413);
  try{$url=cloudinaryUploadSpacePhoto($_FILES['photo']??[],$uid);respond(['url'=>$url],201);}
  catch(InvalidArgumentException $e){respond(['error'=>$e->getMessage()],422);}
  catch(Throwable $e){error_log('Encontro Cloudinary upload: '.get_class($e));respond(['error'=>'Upload de fotos indisponível. Verifique a configuração do armazenamento de imagens.'],503);}
 }
 $pdo=database();
 $q=$pdo->prepare('INSERT INTO users(firebase_uid,email) VALUES (?,?) ON DUPLICATE KEY UPDATE email=VALUES(email)');$q->execute([$uid,$email]);
 $q=$pdo->prepare('SELECT id FROM users WHERE firebase_uid=?');$q->execute([$uid]);$userId=(int)$q->fetchColumn();
 $consentRequirements=accountConsentRequirements();
 if($consentRequirements===null)respond(['error'=>'Cadastro e operações autenticadas temporariamente indisponíveis enquanto os documentos oficiais não estiverem configurados.','code'=>'ACCOUNT_CONSENT_CONFIGURATION_UNAVAILABLE'],503);
 if($action==='account-consent-requirements')respond($consentRequirements);
 if($action==='account-consent-status')respond(['accepted'=>accountConsentIsCurrent($pdo,$userId,$consentRequirements)]);
 if($action==='account-consent'){
  if((int)($_SERVER['CONTENT_LENGTH']??0)>5000)respond(['error'=>'Solicitação muito grande.'],413);
  try{$input=json_decode(file_get_contents('php://input'),true,512,JSON_THROW_ON_ERROR);}catch(Throwable){respond(['error'=>'Dados de consentimento inválidos.'],400);}
  if(!is_array($input)||($input['accepted']??false)!==true)respond(['error'=>'Confirme explicitamente a leitura dos documentos para continuar.'],422);
  if(($input['terms_version']??null)!==$consentRequirements['terms_version']||($input['privacy_version']??null)!==$consentRequirements['privacy_version'])respond(['error'=>'Os documentos foram atualizados. Recarregue a página e confira as versões vigentes.','code'=>'ACCOUNT_CONSENT_VERSION_MISMATCH'],409);
  try{$acceptedAt=accountConsentAccept($pdo,$userId,$consentRequirements);}catch(Throwable $error){error_log('Encontro account consent persistence failed: '.get_class($error));respond(['error'=>'Não foi possível registrar o consentimento. Tente novamente.'],503);}
  respond(['accepted'=>true,'terms_version'=>$consentRequirements['terms_version'],'privacy_version'=>$consentRequirements['privacy_version'],'accepted_at'=>$acceptedAt]);
 }
 if(!accountConsentIsCurrent($pdo,$userId,$consentRequirements))respond(['error'=>'Para continuar, confira e aceite a versão vigente dos Termos de Uso e da Política de Privacidade.','code'=>'ACCOUNT_CONSENT_REQUIRED'],403);
 $platformAdminUid=env('PLATFORM_ADMIN_FIREBASE_UID');
 $isPlatformAdmin=$platformAdminUid!==''&&hash_equals($platformAdminUid,(string)$uid);
 if($action==='owner-requests'){
  if(!$isPlatformAdmin)respond(['error'=>'Acesso restrito ao administrador da plataforma.'],403);
  $q=$pdo->query("SELECT u.firebase_uid,u.email,u.name,u.phone,u.owner_status,u.created_at,COUNT(s.id) AS spaces_count FROM users u LEFT JOIN spaces s ON s.owner_id=u.id WHERE u.owner_status='pending' GROUP BY u.id ORDER BY u.created_at ASC");
  respond($q->fetchAll(PDO::FETCH_ASSOC));
 }
 if($action==='review-owner'){
  if(!$isPlatformAdmin)respond(['error'=>'Acesso restrito ao administrador da plataforma.'],403);
  if((int)($_SERVER['CONTENT_LENGTH']??0)>5000)respond(['error'=>'Solicitação muito grande.'],413);
  $input=json_decode(file_get_contents('php://input'),true,512,JSON_THROW_ON_ERROR);
  $targetUid=$input['firebase_uid']??'';$decision=$input['decision']??'';
  if(!is_string($targetUid)||$targetUid===''||strlen($targetUid)>128||$targetUid===$uid||!in_array($decision,['approved','rejected'],true))respond(['error'=>'Solicitação de aprovação inválida.'],422);
  $pdo->beginTransaction();
  try{
   $q=$pdo->prepare("SELECT id,owner_status FROM users WHERE firebase_uid=? FOR UPDATE");$q->execute([$targetUid]);$target=$q->fetch(PDO::FETCH_ASSOC);
   if(!$target||$target['owner_status']!=='pending'){$pdo->rollBack();respond(['error'=>'Solicitação de proprietário não encontrada ou já analisada.'],404);}
   $q=$pdo->prepare('UPDATE users SET owner_status=? WHERE id=?');$q->execute([$decision,(int)$target['id']]);
   if($decision==='rejected'){$q=$pdo->prepare('UPDATE spaces SET active=0 WHERE owner_id=?');$q->execute([(int)$target['id']]);}
   $pdo->commit();
  }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
  respond(['firebase_uid'=>$targetUid,'owner_status'=>$decision]);
 }
 if($action==='review-reservation'){
  if((int)($_SERVER['CONTENT_LENGTH']??0)>5000)respond(['error'=>'Solicitação muito grande.'],413);
  $input=json_decode(file_get_contents('php://input'),true,512,JSON_THROW_ON_ERROR);
  $reservationId=$input['reservation_id']??'';$decision=$input['decision']??'';
  if(!is_string($reservationId)||!preg_match('/^[a-f0-9]{32}$/',$reservationId)||!in_array($decision,['confirmed','declined'],true))respond(['error'=>'Decisão de reserva inválida.'],422);
  $q=$pdo->prepare('SELECT space_id FROM reservations WHERE id=?');$q->execute([$reservationId]);$spaceId=$q->fetchColumn();
  if(!$spaceId)respond(['error'=>'Solicitação não encontrada.'],404);
  $pdo->beginTransaction();
  try{
   $q=$pdo->prepare('SELECT owner_id FROM spaces WHERE id=? FOR UPDATE');$q->execute([$spaceId]);$ownerId=$q->fetchColumn();
   if((int)$ownerId!==(int)$userId){$pdo->rollBack();respond(['error'=>'Solicitação não encontrada para esta conta.'],404);}
   $q=$pdo->prepare('SELECT status,check_in,blocked_until FROM reservations WHERE id=? AND space_id=? FOR UPDATE');$q->execute([$reservationId,$spaceId]);$reservation=$q->fetch(PDO::FETCH_ASSOC);
   if(!$reservation||$reservation['status']!=='requested'){$pdo->rollBack();respond(['error'=>'Solicitação não está aguardando confirmação.'],409);}
   if($decision==='confirmed'){
    $q=$pdo->prepare("SELECT id FROM reservations WHERE space_id=? AND id<>? AND check_in < ? AND blocked_until > ? AND status IN ('creating','requested','confirmed','pending','approved') LIMIT 1");
    $q->execute([$spaceId,$reservationId,$reservation['blocked_until'],$reservation['check_in']]);
    if($q->fetchColumn()){$pdo->rollBack();respond(['error'=>'O período não está mais disponível para confirmação.'],409);}
   }
   $q=$pdo->prepare('UPDATE reservations SET status=? WHERE id=? AND status=\'requested\'');$q->execute([$decision,$reservationId]);
   $pdo->commit();
  }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
  respond(['reservation_id'=>$reservationId,'status'=>$decision]);
 }

 if($action==='chat-access'){
  if(!requestIsSecure())respond(['error'=>'HTTPS obrigatório.'],400);
  if((int)($_SERVER['CONTENT_LENGTH']??0)>5000)respond(['error'=>'Solicitação muito grande.'],413);
  $input=json_decode(file_get_contents('php://input'),true,512,JSON_THROW_ON_ERROR);
  $reservationId=$input['reservation_id']??'';
  if(!is_string($reservationId)||!preg_match('/^[a-f0-9]{32}$/',$reservationId))respond(['error'=>'Reserva inválida.'],422);
  $q=$pdo->prepare('SELECT r.id FROM reservations r JOIN spaces s ON s.id=r.space_id WHERE r.id=? AND (r.user_id=? OR s.owner_id=?) LIMIT 1');
  $q->execute([$reservationId,$userId,$userId]);
  if(!$q->fetchColumn())respond(['error'=>'Conversa não encontrada para esta conta.'],404);
  try{firebase()->createDatabase()->getReference('chats/'.$reservationId.'/members/'.$uid)->set(true);}
  catch(Throwable $e){error_log('Encontro chat membership: '.get_class($e));respond(['error'=>'Não foi possível autorizar a conversa agora.'],503);}
  respond(['authorized'=>true]);
 }
 if($action==='delete-space-photo'){
  if(!requestIsSecure())respond(['error'=>'HTTPS obrigatório.'],400);
  if((int)($_SERVER['CONTENT_LENGTH']??0)>5000)respond(['error'=>'Solicitação muito grande.'],413);
  $input=json_decode(file_get_contents('php://input'),true,512,JSON_THROW_ON_ERROR);$url=$input['url']??'';
  if(!is_string($url)||strlen($url)>2048)respond(['error'=>'Foto inválida.'],422);
  $ownerHash=hash('sha256',$uid);$publicId=cloudinarySpacePhotoPublicId($url,$ownerHash);
  if($publicId===null)respond(['error'=>'Esta foto não pertence à sua conta.'],403);
  $q=$pdo->prepare('SELECT photos FROM spaces WHERE owner_id=?');$q->execute([$userId]);
  foreach($q->fetchAll(PDO::FETCH_COLUMN) as $stored){$items=json_decode((string)$stored,true);if(is_array($items)&&in_array($url,$items,true))respond(['error'=>'Esta foto já está associada a um anúncio.'],409);}
  try{cloudinaryDeleteSpacePhoto($url,$uid);}catch(Throwable $e){error_log('Encontro Cloudinary delete: '.get_class($e));respond(['error'=>'Não foi possível remover a foto agora.'],503);}
  respond(['deleted'=>true]);
 }
 if($action==='my-spaces'){$q=$pdo->prepare('SELECT * FROM spaces WHERE owner_id=? ORDER BY id DESC');$q->execute([$userId]);respond(array_map('publicSpace',$q->fetchAll(PDO::FETCH_ASSOC)));}
 if($action==='host-reservations'){$q=$pdo->prepare('SELECT r.id,r.check_in,r.check_out,r.plan,r.billable_days,r.total,r.status,r.payment_status,r.payment_attention,r.contact_name,r.guests,s.name FROM reservations r JOIN spaces s ON s.id=r.space_id WHERE s.owner_id=? ORDER BY r.created_at DESC');$q->execute([$userId]);respond($q->fetchAll(PDO::FETCH_ASSOC));}
 if($action==='save-space'){
  if((int)($_SERVER['CONTENT_LENGTH']??0)>40000)respond(['error'=>'Solicitação muito grande.'],413);
  $input=json_decode(file_get_contents('php://input'),true,512,JSON_THROW_ON_ERROR);if(!is_array($input))respond(['error'=>'Dados inválidos.'],400);
  $pdo->beginTransaction();
  $q=$pdo->prepare('SELECT owner_status FROM users WHERE id=? FOR UPDATE');$q->execute([$userId]);$ownerStatus=(string)$q->fetchColumn();
  if($ownerStatus!=='approved'){$q=$pdo->prepare("UPDATE users SET owner_status='pending' WHERE id=? AND owner_status IN ('none','rejected')");$q->execute([$userId]);$ownerStatus='pending';}
  try{$data=validateSpace($input,hash('sha256',$uid),$ownerStatus);}catch(InvalidArgumentException $e){$pdo->rollBack();respond(['error'=>$e->getMessage()],422);}
  $id=$input['id']??null;$oldPhotos=[];
  if($id!==null){
   if(!filter_var($id,FILTER_VALIDATE_INT)||$id<1){$pdo->rollBack();respond(['error'=>'Espaço inválido.'],422);}
   $q=$pdo->prepare('SELECT id,photos FROM spaces WHERE id=? AND owner_id=? FOR UPDATE');$q->execute([$id,$userId]);$existingSpace=$q->fetch(PDO::FETCH_ASSOC);
   if(!$existingSpace){$pdo->rollBack();respond(['error'=>'Espaço não encontrado para esta conta.'],404);}
   $oldPhotos=json_decode($existingSpace['photos']??'[]',true);$oldPhotos=is_array($oldPhotos)?array_values(array_filter($oldPhotos,'is_string')):[];
   $sets=implode(',',array_map(fn($key)=>$key.'=?',array_keys($data)));$q=$pdo->prepare('UPDATE spaces SET '.$sets.',is_demo=0 WHERE id=? AND owner_id=?');$q->execute([...array_values($data),$id,$userId]);
  }else{
   $data['owner_id']=$userId;$data['is_demo']=0;$columns=implode(',',array_keys($data));$marks=implode(',',array_fill(0,count($data),'?'));
   $q=$pdo->prepare('INSERT INTO spaces ('.$columns.') VALUES ('.$marks.')');$q->execute(array_values($data));$id=(int)$pdo->lastInsertId();
  }
  $pdo->commit();
  if($oldPhotos){
   $q=$pdo->prepare('SELECT photos FROM spaces WHERE owner_id=?');$q->execute([$userId]);$remaining=[];
   foreach($q->fetchAll(PDO::FETCH_COLUMN) as $stored){$items=json_decode((string)$stored,true);if(is_array($items))foreach($items as $item)if(is_string($item))$remaining[$item]=true;}
   foreach(array_unique($oldPhotos) as $oldUrl){if(isset($remaining[$oldUrl]))continue;try{cloudinaryDeleteSpacePhoto($oldUrl,$uid);}catch(Throwable $cleanupError){error_log('Encontro: falha ao limpar foto antiga do Cloudinary.');}}
  }
  $q=$pdo->prepare('SELECT * FROM spaces WHERE id=? AND owner_id=?');$q->execute([$id,$userId]);respond(publicSpace($q->fetch(PDO::FETCH_ASSOC)),201);
 }
 if($action==='reservations'){$q=$pdo->prepare('SELECT r.id,r.event_date,r.check_in,r.check_out,r.plan,r.billable_days,r.total,r.status,r.payment_status,r.payment_attention,s.name FROM reservations r JOIN spaces s ON s.id=r.space_id WHERE r.user_id=? ORDER BY r.created_at DESC');$q->execute([$userId]);respond($q->fetchAll(PDO::FETCH_ASSOC));}
 if($action==='profile'&&$method==='GET'){$q=$pdo->prepare('SELECT name,phone,owner_status FROM users WHERE id=?');$q->execute([$userId]);$profile=$q->fetch(PDO::FETCH_ASSOC);$profile['is_platform_admin']=$isPlatformAdmin;respond($profile);}
 if($action==='reserve'&&env('RESERVATIONS_PAYMENT_MODE','request')==='payment'&&(!env('MP_ACCESS_TOKEN')||!env('MP_WEBHOOK_SECRET')))respond(['error'=>'Pagamentos ainda não configurados.'],503);
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
 if(!$preSpace||!$preSpace['active']||!empty($preSpace['is_demo'])||$guests>(int)$preSpace['capacity'])respond(['error'=>'Espaço indisponível ou capacidade inválida.'],422);
 $plan=$input['plan']??'daily';
 try{$preQuote=bookingQuote($input['check_in']??null,$input['check_out']??null,$plan,(string)$preSpace['daily_price'],$preSpace['weekend_price']===null?null:(string)$preSpace['weekend_price']);enforceSpaceSchedule($preSpace,$preQuote);}
 catch(InvalidArgumentException $e){respond(['error'=>$e->getMessage()],422);}
 $preBlockedUntil=(new DateTimeImmutable($preQuote['check_out'],new DateTimeZone('America/Sao_Paulo')))->modify('+'.(int)$preSpace['cleanup_minutes'].' minutes')->format('Y-m-d H:i:s');
 if(env('RESERVATIONS_PAYMENT_MODE','request')==='payment')paymentLifecycleReconcileOverlappingHolds($pdo,(int)$spaceId,$preQuote['check_in'],$preBlockedUntil);
 $pdo->beginTransaction();$q=$pdo->prepare('SELECT * FROM spaces WHERE id=? FOR UPDATE');$q->execute([$spaceId]);$space=$q->fetch(PDO::FETCH_ASSOC);
 if(!$space||!$space['active']||!empty($space['is_demo'])||$guests>$space['capacity']){$pdo->rollBack();respond(['error'=>'Espaço indisponível ou capacidade inválida.'],422);}
 try{$quote=bookingQuote($input['check_in']??null,$input['check_out']??null,$plan,(string)$space['daily_price'],$space['weekend_price']===null?null:(string)$space['weekend_price']);enforceSpaceSchedule($space,$quote);}
 catch(InvalidArgumentException $e){$pdo->rollBack();respond(['error'=>$e->getMessage()],422);}
 $blockedUntil=(new DateTimeImmutable($quote['check_out'],new DateTimeZone('America/Sao_Paulo')))->modify('+'.(int)$space['cleanup_minutes'].' minutes')->format('Y-m-d H:i:s');
 $q=$pdo->prepare("SELECT id FROM reservations WHERE space_id=? AND check_in < ? AND blocked_until > ? AND status IN ('creating','requested','confirmed','pending','approved') LIMIT 1");$q->execute([$spaceId,$blockedUntil,$quote['check_in']]);
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
  if(env('RESERVATIONS_PAYMENT_MODE','request')!=='payment'){
   $q=$pdo->prepare("UPDATE reservations SET status='requested' WHERE id=? AND status='creating'");$q->execute([$id]);
   respond(['reservation_id'=>$id,'status'=>'requested'],201);
  }
  $url=rtrim(env('APP_URL'),'/');if(!str_starts_with($url,'https://'))throw new RuntimeException('Configure APP_URL com HTTPS para pagamentos.');
  $preferenceStartsAt=new DateTimeImmutable('now',new DateTimeZone('America/Sao_Paulo'));
  $preferenceExpiresAt=$preferenceStartsAt->modify('+3 days');
  $holdExpiresAt=$preferenceExpiresAt->modify('+6 hours');
  $preference=mp('/checkout/preferences',['items'=>[['id'=>(string)$spaceId,'title'=>$space['name'].' — '.$quote['check_in'].' a '.$quote['check_out'].' ('.$quote['days'].' diárias)','quantity'=>1,'currency_id'=>'BRL','unit_price'=>(float)$quote['total']]],'external_reference'=>$id,'payer'=>['email'=>$email],'notification_url'=>$url.'/api.php?action=webhook','back_urls'=>['success'=>$url.'/?payment=success','pending'=>$url.'/?payment=pending','failure'=>$url.'/?payment=failure'],'auto_return'=>'approved','expires'=>true,'expiration_date_from'=>$preferenceStartsAt->format('Y-m-d\\TH:i:s.vP'),'expiration_date_to'=>$preferenceExpiresAt->format('Y-m-d\\TH:i:s.vP'),'date_of_expiration'=>$preferenceExpiresAt->format('Y-m-d\\TH:i:s.vP')]);
  $q=$pdo->prepare("UPDATE reservations SET status='pending',preference_id=?,hold_expires_at=? WHERE id=? AND status='creating'");$q->execute([$preference['id'],$holdExpiresAt->format('Y-m-d H:i:s'),$id]);
  respond(['reservation_id'=>$id,'checkout_url'=>checkoutUrl($preference,env('MP_SANDBOX','true')==='true')],201);
 }catch(Throwable $e){$q=$pdo->prepare("UPDATE reservations SET status='failed' WHERE id=? AND status='creating'");$q->execute([$id]);throw $e;}
}catch(JsonException $e){respond(['error'=>'Dados inválidos.'],400);}catch(PDOException $e){if(isset($pdo)&&$pdo->inTransaction())$pdo->rollBack();$info=$e->errorInfo;$driverCode=is_array($info)&&isset($info[1])?(string)$info[1]:'n/a';$message=preg_replace('/((?:password|passwd|pwd)\\s*[=:]\\s*)[^;\\s]*/i','$1[redacted]',$e->getMessage())??'diagnostic unavailable';error_log('Encontro API: PDOException SQLSTATE='.$e->getCode().' driver='.$driverCode.' message='.$message);respond(['error'=>'Serviço temporariamente indisponível. Verifique a configuração ou tente novamente.'],503);}catch(Throwable $e){if(isset($pdo)&&$pdo->inTransaction())$pdo->rollBack();error_log('Encontro API: '.get_class($e));respond(['error'=>'Serviço temporariamente indisponível. Verifique a configuração ou tente novamente.'],503);}
