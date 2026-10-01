<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');
if(!is_file(dirname(__DIR__).'/vendor/autoload.php'))respondUnavailable();
function respondUnavailable(): never { http_response_code(503);echo '{"error":"Servidor em configuração. Instale as dependências PHP e configure as credenciais."}';exit; }
require dirname(__DIR__).'/src/bootstrap.php';
date_default_timezone_set('America/Sao_Paulo');
try {
 $action=$_GET['action']??'';$method=$_SERVER['REQUEST_METHOD'];
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
 if(!in_array($action,['reservations','reserve'],true))respond(['error'=>'Rota não encontrada.'],404);
 if(($action==='reserve'&&$method!=='POST')||($action==='reservations'&&$method!=='GET'))respond(['error'=>'Método não permitido.'],405);
 $header=$_SERVER['HTTP_AUTHORIZATION']??'';if(!preg_match('/^Bearer (.+)$/',$header,$match))respond(['error'=>'Entre na sua conta.'],401);
 try{$token=firebase()->createAuth()->verifyIdToken($match[1],true);}catch(Throwable $e){respond(['error'=>'Sessão inválida. Entre novamente.'],401);}
 $uid=$token->claims()->get('sub');$email=$token->claims()->get('email','');$pdo=database();
 $q=$pdo->prepare('INSERT INTO users(firebase_uid,email) VALUES (?,?) ON DUPLICATE KEY UPDATE email=VALUES(email)');$q->execute([$uid,$email]);
 $q=$pdo->prepare('SELECT id FROM users WHERE firebase_uid=?');$q->execute([$uid]);$userId=$q->fetchColumn();
 if($action==='reservations'){$q=$pdo->prepare('SELECT r.id,r.event_date,r.total,r.status,s.name FROM reservations r JOIN spaces s ON s.id=r.space_id WHERE r.user_id=? ORDER BY r.created_at DESC');$q->execute([$userId]);respond($q->fetchAll(PDO::FETCH_ASSOC));}
 if(!env('MP_ACCESS_TOKEN')||!env('MP_WEBHOOK_SECRET'))respond(['error'=>'Pagamentos ainda não configurados.'],503);
 if((int)($_SERVER['CONTENT_LENGTH']??0)>12000)respond(['error'=>'Solicitação muito grande.'],413);
 $input=json_decode(file_get_contents('php://input'),true,512,JSON_THROW_ON_ERROR);
 $date=$input['date']??'';$parsed=is_string($date)?DateTimeImmutable::createFromFormat('!Y-m-d',$date):false;
 $guests=filter_var($input['guests']??null,FILTER_VALIDATE_INT);$spaceId=filter_var($input['space_id']??null,FILTER_VALIDATE_INT);
 $phone=$input['phone']??'';$message=$input['message']??'';
 if(!$parsed||$parsed->format('Y-m-d')!==$date||$date<date('Y-m-d')||!$guests||$guests<1||!$spaceId||!is_string($phone)||strlen($phone)<8||strlen($phone)>25||!is_string($message)||!trim($message)||strlen($message)>8000)respond(['error'=>'Confira a data e os dados do evento.'],422);
 $pdo->beginTransaction();$q=$pdo->prepare('SELECT * FROM spaces WHERE id=? FOR UPDATE');$q->execute([$spaceId]);$space=$q->fetch(PDO::FETCH_ASSOC);
 if(!$space||$guests>$space['capacity']){$pdo->rollBack();respond(['error'=>'Capacidade inválida.'],422);}
 $q=$pdo->prepare("SELECT id FROM reservations WHERE space_id=? AND event_date=? AND status IN ('creating','pending','approved') LIMIT 1");$q->execute([$spaceId,$date]);
 if($q->fetchColumn()){$pdo->rollBack();respond(['error'=>'Esta data já tem uma solicitação ativa. Escolha outra data ou contate o atendimento.'],409);}
 $id=bin2hex(random_bytes(16));$q=$pdo->prepare('INSERT INTO reservations(id,user_id,space_id,event_date,guests,phone,message,total) VALUES (?,?,?,?,?,?,?,?)');$q->execute([$id,$userId,$spaceId,$date,$guests,$phone,trim($message),$space['daily_price']]);$pdo->commit();
 try {
  firebase()->createDatabase()->getReference('chats/'.$id.'/members/'.$uid)->set(true);
  $url=rtrim(env('APP_URL'),'/');if(!str_starts_with($url,'https://'))throw new RuntimeException('Configure APP_URL com HTTPS para pagamentos.');
  $preference=mp('/checkout/preferences',['items'=>[['id'=>(string)$spaceId,'title'=>$space['name'].' — '.$date,'quantity'=>1,'currency_id'=>'BRL','unit_price'=>(float)$space['daily_price']]],'external_reference'=>$id,'payer'=>['email'=>$email],'notification_url'=>$url.'/api.php?action=webhook','back_urls'=>['success'=>$url.'/?payment=success','pending'=>$url.'/?payment=pending','failure'=>$url.'/?payment=failure'],'auto_return'=>'approved']);
  $q=$pdo->prepare("UPDATE reservations SET status='pending',preference_id=? WHERE id=? AND status='creating'");$q->execute([$preference['id'],$id]);
  respond(['reservation_id'=>$id,'checkout_url'=>$preference[env('MP_SANDBOX','true')==='true'?'sandbox_init_point':'init_point']],201);
 }catch(Throwable $e){$q=$pdo->prepare("UPDATE reservations SET status='failed' WHERE id=? AND status='creating'");$q->execute([$id]);throw $e;}
}catch(JsonException $e){respond(['error'=>'Dados inválidos.'],400);}catch(Throwable $e){if(isset($pdo)&&$pdo->inTransaction())$pdo->rollBack();error_log('Encontro API: '.get_class($e));respond(['error'=>'Serviço temporariamente indisponível. Verifique a configuração ou tente novamente.'],503);}
