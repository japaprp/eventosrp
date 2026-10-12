<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');
if(!is_file(dirname(__DIR__).'/vendor/autoload.php'))respondUnavailable();
function respondUnavailable(): never { http_response_code(503);echo '{"error":"Servidor em configuração. Instale as dependências PHP e configure as credenciais."}';exit; }
require dirname(__DIR__).'/src/bootstrap.php';
require dirname(__DIR__).'/src/booking.php';
require dirname(__DIR__).'/src/spaces.php';
date_default_timezone_set('America/Sao_Paulo');
function cloudinaryPhotoConfiguration(): array {
 $cloud=trim((string)env('CLOUDINARY_CLOUD_NAME',''));
 $key=trim((string)env('CLOUDINARY_API_KEY',''));
 $secret=trim((string)env('CLOUDINARY_API_SECRET',''));
 if(!preg_match('/^[a-zA-Z0-9_-]{1,100}$/',$cloud)||$key===''||$secret==='')throw new RuntimeException('O armazenamento de fotos ainda não está configurado.');
 return [$cloud,$key,$secret];
}
function cloudinaryPhotoSignature(array $parameters,string $secret): string {
 ksort($parameters,SORT_STRING);$parts=[];
 foreach($parameters as $name=>$value)if($value!==null&&$value!=='')$parts[]=$name.'='.$value;
 return sha1(implode('&',$parts).$secret);
}
function cloudinaryPhotoApiRequest(string $operation,array $parameters,?CURLFile $file=null): array {
 [$cloud,$key,$secret]=cloudinaryPhotoConfiguration();
 $signatureParameters=$parameters;
 $parameters['api_key']=$key;$parameters['signature']=cloudinaryPhotoSignature($signatureParameters,$secret);
 $fields=$parameters;if($file!==null)$fields['file']=$file;
 $path=$operation==='upload'?'image/upload':($operation==='destroy'?'image/destroy':null);
 if($path===null)throw new RuntimeException('Operação de imagem inválida.');
 $handle=curl_init('https://api.cloudinary.com/v1_1/'.$cloud.'/'.$path);
 if($handle===false)throw new RuntimeException('Não foi possível iniciar a conexão de imagens.');
 curl_setopt_array($handle,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$fields,CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>30,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2]);
 $raw=curl_exec($handle);$status=(int)curl_getinfo($handle,CURLINFO_RESPONSE_CODE);curl_close($handle);
 if(!is_string($raw)||$status<200||$status>=300)throw new RuntimeException('O serviço de imagens não concluiu a operação.');
 $result=json_decode($raw,true);
 if(!is_array($result))throw new RuntimeException('O serviço de imagens retornou uma resposta inválida.');
 return $result;
}
function cloudinarySpacePhotoPublicId(string $url,string $ownerHash): ?string {
 [$cloud]=cloudinaryPhotoConfiguration();
 $parts=parse_url($url);$prefix='/'.$cloud.'/image/upload/';
 if(!is_array($parts)||($parts['scheme']??'')!=='https'||($parts['host']??'')!=='res.cloudinary.com'||isset($parts['user'])||isset($parts['pass']))return null;
 $path=$parts['path']??'';
 if(!str_starts_with($path,$prefix))return null;
 $asset=substr($path,strlen($prefix));$asset=preg_replace('/^v[0-9]+\//','',$asset)??$asset;
 if(!preg_match('~^eventosrp/spaces/'.preg_quote($ownerHash,'~').'/[a-f0-9]{32}(?:\.jpg)?$~D',$asset))return null;
 return preg_replace('/\.jpg$/','',$asset)??$asset;
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
  $payment=mp('/v1/payments/'.rawurlencode($id));$reference=$payment['external_reference']??'';
  $pdo=database();$q=$pdo->prepare('SELECT * FROM reservations WHERE id=?');$q->execute([$reference]);$reservation=$q->fetch(PDO::FETCH_ASSOC);
  if(!$reservation)respond(['received'=>true]);
  if(($payment['currency_id']??'')!=='BRL'||abs((float)$payment['transaction_amount']-(float)$reservation['total'])>0.001)respond(['error'=>'Pagamento inconsistente.'],400);
  if(($payment['status']??'')==='approved'){$q=$pdo->prepare("UPDATE reservations SET status='approved',payment_id=? WHERE id=? AND status IN ('creating','pending','approved')");$q->execute([$id,$reference]);}
  respond(['received'=>true]);
 }
 if(!in_array($action,['reservations','reserve','profile','my-spaces','save-space','host-reservations','upload-space-photo','delete-space-photo'],true))respond(['error'=>'Rota não encontrada.'],404);
 if((in_array($action,['my-spaces','host-reservations'],true)&&$method!=='GET')||(in_array($action,['save-space','upload-space-photo','delete-space-photo'],true)&&$method!=='POST'))respond(['error'=>'Método não permitido.'],405);
 if(($action==='reserve'&&$method!=='POST')||($action==='reservations'&&$method!=='GET')||($action==='profile'&&!in_array($method,['GET','POST'],true)))respond(['error'=>'Método não permitido.'],405);
 $header=$_SERVER['HTTP_AUTHORIZATION']??'';if(!preg_match('/^Bearer (.+)$/',$header,$match))respond(['error'=>'Entre na sua conta.'],401);
 try{$token=firebase()->createAuth()->verifyIdToken($match[1],true);}catch(Throwable $e){respond(['error'=>'Sessão inválida. Entre novamente.'],401);}
 $uid=$token->claims()->get('sub');$email=$token->claims()->get('email','');
 if($action==='upload-space-photo'){
  if((int)($_SERVER['CONTENT_LENGTH']??0)>3*1024*1024)respond(['error'=>'Solicitação muito grande.'],413);
  if(!env('CLOUDINARY_CLOUD_NAME')||!env('CLOUDINARY_API_KEY')||!env('CLOUDINARY_API_SECRET'))respond(['error'=>'Configure CLOUDINARY_CLOUD_NAME, CLOUDINARY_API_KEY e CLOUDINARY_API_SECRET no serviço Render.'],503);
  $file=$_FILES['photo']??null;
  if(!is_array($file)||($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK)respond(['error'=>'Selecione uma foto válida de até 2 MB.'],422);
  if(!is_uploaded_file($file['tmp_name'])||$file['size']<1||$file['size']>2*1024*1024)respond(['error'=>'Cada foto pode ter até 2 MB após a otimização.'],413);
  $image=@getimagesize($file['tmp_name']);
  if(!$image||$image['mime']!=='image/jpeg'||$image[0]>8192||$image[1]>8192)respond(['error'=>'Não foi possível validar a foto otimizada.'],422);
  $owner=hash('sha256',$uid);$publicId='eventosrp/spaces/'.$owner.'/'.bin2hex(random_bytes(16));$timestamp=time();
  $result=cloudinaryPhotoApiRequest('upload',['public_id'=>$publicId,'timestamp'=>$timestamp,'overwrite'=>'false'],new CURLFile($file['tmp_name'],'image/jpeg','foto.jpg'));
  $url=$result['secure_url']??'';
  if(!is_string($url)||cloudinarySpacePhotoPublicId($url,$owner)!==$publicId)throw new RuntimeException('O serviço de imagens não confirmou o endereço da foto.');
  respond(['url'=>$url],201);
 }
 $pdo=database();
 $q=$pdo->prepare('INSERT INTO users(firebase_uid,email) VALUES (?,?) ON DUPLICATE KEY UPDATE email=VALUES(email)');$q->execute([$uid,$email]);
 $q=$pdo->prepare('SELECT id FROM users WHERE firebase_uid=?');$q->execute([$uid]);$userId=$q->fetchColumn();
 if($action==='delete-space-photo'){
  if((int)($_SERVER['CONTENT_LENGTH']??0)>5000)respond(['error'=>'Solicitação muito grande.'],413);
  $input=json_decode(file_get_contents('php://input'),true,512,JSON_THROW_ON_ERROR);$url=$input['url']??'';
  if(!is_string($url)||strlen($url)>2048)respond(['error'=>'Foto inválida.'],422);
  $owner=hash('sha256',$uid);$publicId=cloudinarySpacePhotoPublicId($url,$owner);
  if($publicId===null)respond(['error'=>'Esta foto não pertence à sua conta.'],403);
  $q=$pdo->prepare('SELECT photos FROM spaces WHERE owner_id=?');$q->execute([$userId]);
  foreach($q->fetchAll(PDO::FETCH_COLUMN) as $stored){$photos=json_decode((string)$stored,true);if(is_array($photos)&&in_array($url,$photos,true))respond(['error'=>'Esta foto já está associada a um anúncio.'],409);}
  $result=cloudinaryPhotoApiRequest('destroy',['public_id'=>$publicId,'timestamp'=>time()]);
  if(!in_array($result['result']??null,['ok','not found'],true))throw new RuntimeException('O serviço de imagens não removeu a foto.');
  respond(['deleted'=>true]);
 }
 if($action==='my-spaces'){$q=$pdo->prepare('SELECT * FROM spaces WHERE owner_id=? ORDER BY id DESC');$q->execute([$userId]);respond(array_map('publicSpace',$q->fetchAll(PDO::FETCH_ASSOC)));}
 if($action==='host-reservations'){$q=$pdo->prepare('SELECT r.id,r.check_in,r.check_out,r.plan,r.billable_days,r.total,r.status,r.contact_name,r.guests,s.name FROM reservations r JOIN spaces s ON s.id=r.space_id WHERE s.owner_id=? ORDER BY r.created_at DESC');$q->execute([$userId]);respond($q->fetchAll(PDO::FETCH_ASSOC));}
 if($action==='save-space'){
  if((int)($_SERVER['CONTENT_LENGTH']??0)>40000)respond(['error'=>'Solicitação muito grande.'],413);
  $input=json_decode(file_get_contents('php://input'),true,512,JSON_THROW_ON_ERROR);if(!is_array($input))respond(['error'=>'Dados inválidos.'],400);
  try{$data=validateSpace($input,hash('sha256',$uid));}catch(InvalidArgumentException $e){respond(['error'=>$e->getMessage()],422);}
  $id=$input['id']??null;$oldPhotos=[];$pdo->beginTransaction();
  if($id!==null){
   if(!filter_var($id,FILTER_VALIDATE_INT)||$id<1){$pdo->rollBack();respond(['error'=>'Espaço inválido.'],422);}
   $q=$pdo->prepare('SELECT id,photos FROM spaces WHERE id=? AND owner_id=? FOR UPDATE');$q->execute([$id,$userId]);$existingSpace=$q->fetch(PDO::FETCH_ASSOC);
   if(!$existingSpace){$pdo->rollBack();respond(['error'=>'Espaço não encontrado para esta conta.'],404);}
   $oldPhotos=json_decode($existingSpace['photos']??'[]',true);$oldPhotos=is_array($oldPhotos)?array_values(array_filter($oldPhotos,'is_string')):[];
   $sets=implode(',',array_map(fn($key)=>$key.'=?',array_keys($data)));$q=$pdo->prepare('UPDATE spaces SET '.$sets.' WHERE id=? AND owner_id=?');$q->execute([...array_values($data),$id,$userId]);
  }else{
   $data['owner_id']=$userId;$data['is_demo']=0;$columns=implode(',',array_keys($data));$marks=implode(',',array_fill(0,count($data),'?'));
   $q=$pdo->prepare('INSERT INTO spaces ('.$columns.') VALUES ('.$marks.')');$q->execute(array_values($data));$id=(int)$pdo->lastInsertId();
  }
  $pdo->commit();
  if($oldPhotos){
   $q=$pdo->prepare('SELECT photos FROM spaces WHERE owner_id=?');$q->execute([$userId]);$remaining=[];
   foreach($q->fetchAll(PDO::FETCH_COLUMN) as $stored){$items=json_decode((string)$stored,true);if(is_array($items))foreach($items as $item)if(is_string($item))$remaining[$item]=true;}
   foreach(array_unique($oldPhotos) as $oldUrl){
    if(isset($remaining[$oldUrl]))continue;
    try{$publicId=cloudinarySpacePhotoPublicId($oldUrl,hash('sha256',$uid));if($publicId!==null)cloudinaryPhotoApiRequest('destroy',['public_id'=>$publicId,'timestamp'=>time()]);}
    catch(Throwable $cleanupError){error_log('Encontro: falha ao limpar foto antiga do Cloudinary.');}
   }
  }
  $q=$pdo->prepare('SELECT * FROM spaces WHERE id=? AND owner_id=?');$q->execute([$id,$userId]);respond(publicSpace($q->fetch(PDO::FETCH_ASSOC)),201);
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
}catch(JsonException $e){respond(['error'=>'Dados inválidos.'],400);}catch(PDOException $e){if(isset($pdo)&&$pdo->inTransaction())$pdo->rollBack();$info=$e->errorInfo;$driverCode=is_array($info)&&isset($info[1])?(string)$info[1]:'n/a';$message=preg_replace('/((?:password|passwd|pwd)\\s*[=:]\\s*)[^;\\s]*/i','$1[redacted]',$e->getMessage())??'diagnostic unavailable';error_log('Encontro API: PDOException SQLSTATE='.$e->getCode().' driver='.$driverCode.' message='.$message);respond(['error'=>'Serviço temporariamente indisponível. Verifique a configuração ou tente novamente.'],503);}catch(Throwable $e){if(isset($pdo)&&$pdo->inTransaction())$pdo->rollBack();error_log('Encontro API: '.get_class($e));respond(['error'=>'Serviço temporariamente indisponível. Verifique a configuração ou tente novamente.'],503);}
