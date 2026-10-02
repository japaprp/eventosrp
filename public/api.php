<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');
if(!is_file(dirname(__DIR__).'/vendor/autoload.php'))respondUnavailable();
function respondUnavailable(): never { http_response_code(503);echo '{"error":"Servidor em configuração. Instale as dependências PHP e configure as credenciais."}';exit; }
require dirname(__DIR__).'/src/bootstrap.php';
require dirname(__DIR__).'/src/booking.php';
require dirname(__DIR__).'/src/spaces.php';
date_default_timezone_set('America/Sao_Paulo');
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
  $payment=mp('/v1/payments/'.rawurlencode($id));$reference=$payment['external_reference']??'';
  $pdo=database();$q=$pdo->prepare('SELECT * FROM reservations WHERE id=?');$q->execute([$reference]);$reservation=$q->fetch(PDO::FETCH_ASSOC);
  if(!$reservation)respond(['received'=>true]);
  if(($payment['currency_id']??'')!=='BRL'||abs((float)$payment['transaction_amount']-(float)$reservation['total'])>0.001)respond(['error'=>'Pagamento inconsistente.'],400);
  if(($payment['status']??'')==='approved'){$q=$pdo->prepare("UPDATE reservations SET status='approved',payment_id=? WHERE id=? AND status IN ('creating','pending','approved')");$q->execute([$id,$reference]);}
  respond(['received'=>true]);
 }
 if(!in_array($action,['reservations','reserve','profile','my-spaces','save-space','host-reservations'],true))respond(['error'=>'Rota não encontrada.'],404);
 if((in_array($action,['my-spaces','host-reservations'],true)&&$method!=='GET')||($action==='save-space'&&$method!=='POST'))respond(['error'=>'Método não permitido.'],405);
 if(($action==='reserve'&&$method!=='POST')||($action==='reservations'&&$method!=='GET')||($action==='profile'&&!in_array($method,['GET','POST'],true)))respond(['error'=>'Método não permitido.'],405);
 $header=$_SERVER['HTTP_AUTHORIZATION']??'';if(!preg_match('/^Bearer (.+)$/',$header,$match))respond(['error'=>'Entre na sua conta.'],401);
 try{$token=firebase()->createAuth()->verifyIdToken($match[1],true);}catch(Throwable $e){respond(['error'=>'Sessão inválida. Entre novamente.'],401);}
 $uid=$token->claims()->get('sub');$email=$token->claims()->get('email','');$pdo=database();
 $q=$pdo->prepare('INSERT INTO users(firebase_uid,email) VALUES (?,?) ON DUPLICATE KEY UPDATE email=VALUES(email)');$q->execute([$uid,$email]);
 $q=$pdo->prepare('SELECT id FROM users WHERE firebase_uid=?');$q->execute([$uid]);$userId=$q->fetchColumn();
 if($action==='my-spaces'){$q=$pdo->prepare('SELECT * FROM spaces WHERE owner_id=? ORDER BY id DESC');$q->execute([$userId]);respond(array_map('publicSpace',$q->fetchAll(PDO::FETCH_ASSOC)));}
 if($action==='host-reservations'){$q=$pdo->prepare('SELECT r.id,r.check_in,r.check_out,r.plan,r.billable_days,r.total,r.status,r.contact_name,r.guests,s.name FROM reservations r JOIN spaces s ON s.id=r.space_id WHERE s.owner_id=? ORDER BY r.created_at DESC');$q->execute([$userId]);respond($q->fetchAll(PDO::FETCH_ASSOC));}
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
 if($action==='reservations'){$q=$pdo->prepare('SELECT r.id,r.event_date,r.check_in,r.check_out,r.plan,r.billable_days,r.total,r.status,s.name FROM reservations r JOIN spaces s ON s.id=r.space_id WHERE r.user_id=? ORDER BY r.created_at DESC');$q->execute([$userId]);respond($q->fetchAll(PDO::FETCH_ASSOC));}
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
 $pdo->beginTransaction();$q=$pdo->prepare('SELECT * FROM spaces WHERE id=? FOR UPDATE');$q->execute([$spaceId]);$space=$q->fetch(PDO::FETCH_ASSOC);
 if(!$space||!$space['active']||$guests>$space['capacity']){$pdo->rollBack();respond(['error'=>'Espaço indisponível ou capacidade inválida.'],422);}
 $plan=$input['plan']??'daily';
 try{$quote=bookingQuote($input['check_in']??null,$input['check_out']??null,$plan,(string)$space['daily_price'],$space['weekend_price']===null?null:(string)$space['weekend_price']);enforceSpaceSchedule($space,$quote);}
 catch(InvalidArgumentException $e){$pdo->rollBack();respond(['error'=>$e->getMessage()],422);}
 $blockedUntil=(new DateTimeImmutable($quote['check_out'],new DateTimeZone('America/Sao_Paulo')))->modify('+'.(int)$space['cleanup_minutes'].' minutes')->format('Y-m-d H:i:s');
 $q=$pdo->prepare("SELECT id FROM reservations WHERE space_id=? AND check_in < ? AND blocked_until > ? AND status IN ('creating','pending','approved') LIMIT 1");$q->execute([$spaceId,$blockedUntil,$quote['check_in']]);
 if($q->fetchColumn()){$pdo->rollBack();respond(['error'=>'Há uma solicitação ativa em parte deste período. Escolha outra entrada ou saída, ou contate o atendimento.'],409);}
 $id=bin2hex(random_bytes(16));$q=$pdo->prepare('INSERT INTO reservations(id,user_id,space_id,event_date,check_in,check_out,blocked_until,plan,billable_days,contact_name,guests,phone,message,total) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)');$q->execute([$id,$userId,$spaceId,$quote['event_date'],$quote['check_in'],$quote['check_out'],$blockedUntil,$plan,$quote['days'],trim($contactName),$guests,$phone,trim($message),$quote['total']]);
 $q=$pdo->prepare('UPDATE users SET name=?,phone=? WHERE id=?');$q->execute([trim($contactName),$phone,$userId]);$pdo->commit();
 try {
  firebase()->createDatabase()->getReference('chats/'.$id.'/members/'.$uid)->set(true);
  if($space['owner_id']){$q=$pdo->prepare('SELECT firebase_uid FROM users WHERE id=?');$q->execute([$space['owner_id']]);$ownerUid=$q->fetchColumn();if($ownerUid)firebase()->createDatabase()->getReference('chats/'.$id.'/members/'.$ownerUid)->set(true);}
  $url=rtrim(env('APP_URL'),'/');if(!str_starts_with($url,'https://'))throw new RuntimeException('Configure APP_URL com HTTPS para pagamentos.');
  $preference=mp('/checkout/preferences',['items'=>[['id'=>(string)$spaceId,'title'=>$space['name'].' — '.$quote['check_in'].' a '.$quote['check_out'].' ('.$quote['days'].' diárias)','quantity'=>1,'currency_id'=>'BRL','unit_price'=>(float)$quote['total']]],'external_reference'=>$id,'payer'=>['email'=>$email],'notification_url'=>$url.'/api.php?action=webhook','back_urls'=>['success'=>$url.'/?payment=success','pending'=>$url.'/?payment=pending','failure'=>$url.'/?payment=failure'],'auto_return'=>'approved']);
  $q=$pdo->prepare("UPDATE reservations SET status='pending',preference_id=? WHERE id=? AND status='creating'");$q->execute([$preference['id'],$id]);
  respond(['reservation_id'=>$id,'checkout_url'=>$preference[env('MP_SANDBOX','true')==='true'?'sandbox_init_point':'init_point']],201);
 }catch(Throwable $e){$q=$pdo->prepare("UPDATE reservations SET status='failed' WHERE id=? AND status='creating'");$q->execute([$id]);throw $e;}
}catch(JsonException $e){respond(['error'=>'Dados inválidos.'],400);}catch(Throwable $e){if(isset($pdo)&&$pdo->inTransaction())$pdo->rollBack();error_log('Encontro API: '.get_class($e));respond(['error'=>'Serviço temporariamente indisponível. Verifique a configuração ou tente novamente.'],503);}
