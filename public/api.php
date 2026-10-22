<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');
if(!is_file(dirname(__DIR__).'/vendor/autoload.php'))respondUnavailable();
function respondUnavailable(): never { http_response_code(503);echo '{"error":"Servidor em configuração. Instale as dependências PHP e configure as credenciais."}';exit; }
require dirname(__DIR__).'/src/bootstrap.php';
require dirname(__DIR__).'/src/booking.php';
require dirname(__DIR__).'/src/spaces.php';
require dirname(__DIR__).'/src/reservations.php';
require dirname(__DIR__).'/src/checkout.php';
date_default_timezone_set('America/Sao_Paulo');
try {
 $action=$_GET['action']??'';$method=$_SERVER['REQUEST_METHOD'];
 if($action==='space-photo'&&$method==='GET'){
  $owner=$_GET['owner']??'';$id=$_GET['id']??'';
  if(!is_string($owner)||!preg_match('/^[a-f0-9]{64}$/',$owner)||!is_string($id)||!preg_match('/^[a-f0-9]{32}$/',$id))respond(['error'=>'Foto não encontrada.'],404);
  $path=dirname(__DIR__).'/var/space-photos/'.$owner.'/'.$id.'.jpg';
  if(!is_file($path)||is_link($path)||is_link(dirname($path)))respond(['error'=>'Foto não encontrada.'],404);
  $image=@getimagesize($path);if(!$image||$image['mime']!=='image/jpeg')respond(['error'=>'Foto inválida.'],404);
  header_remove('Content-Type');header('Content-Type: image/jpeg');header('Cache-Control: public, max-age=31536000, immutable');header('Content-Length: '.filesize($path));header('X-Content-Type-Options: nosniff');header('Content-Disposition: inline; filename="espaco.jpg"');readfile($path);exit;
 }
 if($action==='spaces'&&$method==='GET'){$rows=database()->query('SELECT * FROM spaces WHERE active=1 AND is_demo=0 ORDER BY id DESC')->fetchAll(PDO::FETCH_ASSOC);respond(array_map('publicSpace',$rows));}
 if($action==='availability'&&$method==='GET'){
  $checkIn=$_GET['check_in']??'';$checkOut=$_GET['check_out']??'';$plan=$_GET['plan']??'daily';
  if(!is_string($checkIn)||!is_string($checkOut)||!preg_match('/^\d{4}-\d{2}-\d{2}$/',$checkIn)||!preg_match('/^\d{4}-\d{2}-\d{2}$/',$checkOut)||!in_array($plan,['daily','weekend'],true))respond(['error'=>'Informe uma entrada, saída e tipo de reserva válidos.'],422);
  $zone=new DateTimeZone('America/Sao_Paulo');$today=new DateTimeImmutable('today',$zone);
  foreach([$checkIn,$checkOut] as $date){$selected=DateTimeImmutable::createFromFormat('!Y-m-d',$date,$zone);if(!$selected||$selected->format('Y-m-d')!==$date)respond(['error'=>'Informe datas válidas para consultar.'],422);if($selected<$today)respond(['error'=>'As datas precisam ser hoje ou futuras.'],422);}
  if($checkOut<=$checkIn)respond(['error'=>'A saída precisa ser depois da entrada.'],422);
  $startDate=DateTimeImmutable::createFromFormat('!Y-m-d',$checkIn,$zone);$endDate=DateTimeImmutable::createFromFormat('!Y-m-d',$checkOut,$zone);$duration=(int)$startDate->diff($endDate)->days;
  if($duration>30)respond(['error'=>'Para períodos acima de 30 diárias, consulte o atendimento.'],422);
  if($plan==='weekend'&&(!in_array((int)DateTimeImmutable::createFromFormat('!Y-m-d',$checkIn,$zone)->format('w'),[5,6],true)||$duration<2))respond(['error'=>'O pacote exige entrada na sexta ou no sábado e pelo menos 48 horas.'],422);
  $pdo=database();$rows=$pdo->query('SELECT id,daily_price,weekend_price,check_in_time,check_out_time,minimum_days,cleanup_minutes FROM spaces WHERE active=1 AND is_demo=0')->fetchAll(PDO::FETCH_ASSOC);$available=[];
  foreach($rows as $space){
   try{$quote=spaceReservationQuote($space,$checkIn,$checkOut,$plan);}
   catch(InvalidArgumentException){continue;}
   $q=$pdo->prepare("SELECT id FROM reservations WHERE space_id=? AND check_in < ? AND blocked_until > ? AND status IN ('creating','pending','approved') LIMIT 1");$q->execute([$space['id'],$quote['blocked_until'],$quote['check_in']]);
   if(!$q->fetchColumn())$available[]=['id'=>(int)$space['id'],'days'=>$quote['days'],'total'=>(float)$quote['total'],'plan'=>$plan,'check_in'=>$quote['check_in'],'check_out'=>$quote['check_out']];
  }
  respond(['check_in'=>$checkIn,'check_out'=>$checkOut,'plan'=>$plan,'spaces'=>$available]);
 }
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
 if(!in_array($action,['reservations','reserve','profile','profile-photo','my-spaces','save-space','host-reservations','upload-space-photo','upload-profile-photo'],true))respond(['error'=>'Rota não encontrada.'],404);
 if((in_array($action,['my-spaces','host-reservations','profile-photo'],true)&&$method!=='GET')||(in_array($action,['save-space','upload-space-photo','upload-profile-photo'],true)&&$method!=='POST'))respond(['error'=>'Método não permitido.'],405);
 if(($action==='reserve'&&$method!=='POST')||($action==='reservations'&&$method!=='GET')||($action==='profile'&&!in_array($method,['GET','POST'],true)))respond(['error'=>'Método não permitido.'],405);
 $header=$_SERVER['HTTP_AUTHORIZATION']??'';if(!preg_match('/^Bearer (.+)$/',$header,$match))respond(['error'=>'Entre na sua conta.'],401);
 try{$token=firebase()->createAuth()->verifyIdToken($match[1],true);}catch(Throwable $e){respond(['error'=>'Sessão inválida. Entre novamente.'],401);}
 $uid=$token->claims()->get('sub');$email=$token->claims()->get('email','');
 if($action==='profile-photo'){
  $path=dirname(__DIR__).'/var/profile-photos/'.hash('sha256',$uid).'.jpg';
  if(!is_file($path)||is_link($path)||is_link(dirname($path)))respond(['error'=>'Você ainda não adicionou uma foto de perfil.'],404);
  $image=@getimagesize($path);if(!$image||$image['mime']!=='image/jpeg')respond(['error'=>'Foto de perfil inválida.'],404);
  header_remove('Content-Type');header('Content-Type: image/jpeg');header('Cache-Control: private, no-store');header('Content-Length: '.filesize($path));header('X-Content-Type-Options: nosniff');header('Content-Disposition: inline; filename="perfil.jpg"');readfile($path);exit;
 }
 if($action==='upload-space-photo'){
  if((int)($_SERVER['CONTENT_LENGTH']??0)>3*1024*1024)respond(['error'=>'Cada foto pode ter até 2 MB.'],413);
  $file=$_FILES['photo']??null;
  if(!is_array($file)||($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK)respond(['error'=>'Selecione uma foto válida de até 2 MB.'],422);
  if(!is_uploaded_file($file['tmp_name'])||$file['size']<1||$file['size']>2*1024*1024)respond(['error'=>'Cada foto pode ter até 2 MB.'],413);
  $image=@getimagesize($file['tmp_name']);
  if(!$image||$image['mime']!=='image/jpeg'||$image[0]>8192||$image[1]>8192)respond(['error'=>'Não foi possível validar a foto. Selecione uma imagem JPEG.'],422);
  $owner=hash('sha256',$uid);$directory=dirname(__DIR__).'/var/space-photos/'.$owner;
  if(!is_dir($directory)&&!mkdir($directory,0700,true)&&!is_dir($directory))throw new RuntimeException('Não foi possível preparar o armazenamento de fotos.');
  if(is_link($directory)||!is_writable($directory))throw new RuntimeException('O armazenamento de fotos não está disponível.');
  $id=bin2hex(random_bytes(16));$target=$directory.'/'.$id.'.jpg';
  if(!move_uploaded_file($file['tmp_name'],$target))throw new RuntimeException('Não foi possível salvar a foto.');
  respond(['url'=>'api.php?action=space-photo&owner='.$owner.'&id='.$id],201);
 }
 if($action==='upload-profile-photo'){
  if((int)($_SERVER['CONTENT_LENGTH']??0)>3*1024*1024)respond(['error'=>'A foto de perfil pode ter até 2 MB.'],413);
  $file=$_FILES['photo']??null;
  if(!is_array($file)||($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK)respond(['error'=>'Selecione uma foto válida de até 2 MB.'],422);
  if(!is_uploaded_file($file['tmp_name'])||$file['size']<1||$file['size']>2*1024*1024)respond(['error'=>'A foto de perfil pode ter até 2 MB.'],413);
  $image=@getimagesize($file['tmp_name']);
  if(!$image||$image['mime']!=='image/jpeg'||$image[0]>8192||$image[1]>8192)respond(['error'=>'Não foi possível validar a foto. Selecione uma imagem JPEG.'],422);
  $directory=dirname(__DIR__).'/var/profile-photos';
  if(!is_dir($directory)&&!mkdir($directory,0700,true)&&!is_dir($directory))throw new RuntimeException('Não foi possível preparar o armazenamento de fotos.');
  if(is_link($directory)||!is_writable($directory))throw new RuntimeException('O armazenamento de fotos não está disponível.');
  $target=$directory.'/'.hash('sha256',$uid).'.jpg';
  if(!move_uploaded_file($file['tmp_name'],$target))throw new RuntimeException('Não foi possível salvar a foto de perfil.');
  respond(['saved'=>true],201);
 }
 $pdo=database();
 $q=$pdo->prepare('INSERT INTO users(firebase_uid,email) VALUES (?,?) ON DUPLICATE KEY UPDATE email=VALUES(email)');$q->execute([$uid,$email]);
 $q=$pdo->prepare('SELECT id FROM users WHERE firebase_uid=?');$q->execute([$uid]);$userId=$q->fetchColumn();
 if($action==='my-spaces'){$q=$pdo->prepare('SELECT * FROM spaces WHERE owner_id=? ORDER BY id DESC');$q->execute([$userId]);respond(array_map('publicSpace',$q->fetchAll(PDO::FETCH_ASSOC)));}
 if($action==='host-reservations'){$q=$pdo->prepare('SELECT r.id,r.check_in,r.check_out,r.plan,r.billable_days,r.total,r.status,r.contact_name,r.guests,r.terms_snapshot,r.terms_accepted_at,s.name FROM reservations r JOIN spaces s ON s.id=r.space_id WHERE s.owner_id=? ORDER BY r.created_at DESC');$q->execute([$userId]);respond($q->fetchAll(PDO::FETCH_ASSOC));}
 if($action==='save-space'){
  if((int)($_SERVER['CONTENT_LENGTH']??0)>40000)respond(['error'=>'Solicitação muito grande.'],413);
  $input=json_decode(file_get_contents('php://input'),true,512,JSON_THROW_ON_ERROR);if(!is_array($input))respond(['error'=>'Dados inválidos.'],400);
  try{$data=validateSpace($input,hash('sha256',$uid));}catch(InvalidArgumentException $e){respond(['error'=>$e->getMessage()],422);}
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
 if($action==='reservations'){$q=$pdo->prepare('SELECT r.id,r.event_date,r.check_in,r.check_out,r.plan,r.billable_days,r.total,r.status,r.terms_snapshot,r.terms_accepted_at,s.name FROM reservations r JOIN spaces s ON s.id=r.space_id WHERE r.user_id=? ORDER BY r.created_at DESC');$q->execute([$userId]);respond($q->fetchAll(PDO::FETCH_ASSOC));}
 if($action==='profile'&&$method==='GET'){$q=$pdo->prepare('SELECT name,phone FROM users WHERE id=?');$q->execute([$userId]);respond($q->fetch(PDO::FETCH_ASSOC));}
 if($action==='reserve'&&(!env('MP_ACCESS_TOKEN')||!env('MP_WEBHOOK_SECRET')))respond(['error'=>'Pagamentos ainda não configurados.'],503);
 if((int)($_SERVER['CONTENT_LENGTH']??0)>($action==='reserve'?40000:12000))respond(['error'=>'Solicitação muito grande.'],413);
 $input=json_decode(file_get_contents('php://input'),true,512,JSON_THROW_ON_ERROR);
 if(!is_array($input))respond(['error'=>'Dados inválidos.'],400);
 $contactName=$input[$action==='profile'?'name':'contact_name']??'';
 $phone=$input['phone']??'';
 if(!is_string($contactName)||strlen(trim($contactName))<2||strlen($contactName)>480||mb_strlen($contactName)>120||!is_string($phone)||strlen($phone)>25||!preg_match('/^(?:55)?[1-9][0-9][0-9]{8,9}$/',preg_replace('/\D/','',$phone)))respond(['error'=>'Informe seu nome e um celular válido com DDD.'],422);
 if($action==='profile'){$q=$pdo->prepare('UPDATE users SET name=?,phone=? WHERE id=?');$q->execute([trim($contactName),$phone,$userId]);respond(['name'=>trim($contactName),'phone'=>$phone]);}
 $url=checkoutAppUrl(env('APP_URL'));
 try{$reservation=createReservation($pdo,(int)$userId,$input);}
 catch(InvalidArgumentException $e){respond(['error'=>$e->getMessage()],422);}
 catch(ReservationConflictException $e){respond(['error'=>$e->getMessage()],409);}
 $id=$reservation['id'];$space=$reservation['space'];
 $checkout=startReservationCheckout($pdo,$reservation,$email,$url,env('MP_SANDBOX','true')==='true',function()use($pdo,$id,$uid,$space):void{
  firebase()->createDatabase()->getReference('chats/'.$id.'/members/'.$uid)->set(true);
  if($space['owner_id']){$q=$pdo->prepare('SELECT firebase_uid FROM users WHERE id=?');$q->execute([$space['owner_id']]);$ownerUid=$q->fetchColumn();if($ownerUid)firebase()->createDatabase()->getReference('chats/'.$id.'/members/'.$ownerUid)->set(true);}
 },static fn(array $payload):array=>mp('/checkout/preferences',$payload));
 respond($checkout,201);
}catch(CheckoutUnavailableException $e){
 error_log('Encontro checkout: '.get_class($e->getPrevious()));
 respond(['reservation_id'=>$e->reservationId,'error'=>$e->needsReconciliation
  ?'Não foi possível abrir o pagamento. Esta solicitação está retida para conferência. Consulte suas reservas ou o atendimento antes de tentar novamente.'
  :'Não foi possível preparar a reserva para pagamento. Tente novamente mais tarde.'],503);
}catch(JsonException $e){respond(['error'=>'Dados inválidos.'],400);}catch(PDOException $e){if(isset($pdo)&&$pdo->inTransaction())$pdo->rollBack();$info=$e->errorInfo;$driverCode=is_array($info)&&isset($info[1])?(string)$info[1]:'n/a';$message=preg_replace('/((?:password|passwd|pwd)\s*[=:]\s*)[^;\s]*/i','$1[redacted]',$e->getMessage())??'diagnostic unavailable';error_log('Encontro API: PDOException SQLSTATE='.$e->getCode().' driver='.$driverCode.' message='.$message);respond(['error'=>'Serviço temporariamente indisponível. Verifique a configuração ou tente novamente.'],503);}catch(Throwable $e){if(isset($pdo)&&$pdo->inTransaction())$pdo->rollBack();error_log('Encontro API: '.get_class($e));respond(['error'=>'Serviço temporariamente indisponível. Verifique a configuração ou tente novamente.'],503);}
