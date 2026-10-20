<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
Dotenv\Dotenv::createImmutable(dirname(__DIR__))->safeLoad();
function env(string $key, string $default=''): string { return $_ENV[$key] ?? getenv($key) ?: $default; }
function database(): PDO { static $pdo; return $pdo ??= new PDO('mysql:host='.env('DB_HOST','127.0.0.1').';port='.env('DB_PORT','3306').';dbname='.env('DB_NAME','encontro').';charset=utf8mb4',env('DB_USER'),env('DB_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]); }
function eventosrpHttpTestMode(): bool {
 if (!defined('EVENTOSRP_HTTP_TEST') || EVENTOSRP_HTTP_TEST !== true || PHP_SAPI !== 'cli-server' || env('EVENTOSRP_HTTP_TEST') !== '1') return false;
 return env('DB_NAME') === 'eventosrp_http_test'
  && in_array(env('DB_HOST', '127.0.0.1'), ['127.0.0.1', 'localhost', '::1'], true)
  && env('APP_URL') === 'https://eventosrp-test.invalid';
}
function firebase(): mixed { if(eventosrpHttpTestMode()) return new EventosRpHttpTestFirebase(); return (new Kreait\Firebase\Factory())->withServiceAccount(env('FIREBASE_CREDENTIALS'))->withDatabaseUri(env('FIREBASE_DATABASE_URL')); }
function respond(mixed $data,int $status=200): never { http_response_code($status);echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);exit; }
function mp(string $path,?array $payload=null): array {
 if(eventosrpHttpTestMode()) {
  if($path!=='/checkout/preferences'||!is_array($payload)||empty($payload['external_reference'])) throw new RuntimeException('Blocked non-checkout Mercado Pago call in isolated HTTP test.');
  $id='http-test-pref-'.substr(hash('sha256',(string)$payload['external_reference']),0,20);
  return ['id'=>$id,'sandbox_init_point'=>'http://127.0.0.1/mock-checkout/'.$id,'init_point'=>'http://127.0.0.1/mock-checkout/'.$id];
 }
 $curl=curl_init('https://api.mercadopago.com'.$path);
 curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>20,CURLOPT_HTTPHEADER=>['Authorization: Bearer '.env('MP_ACCESS_TOKEN'),'Content-Type: application/json']]);
 if($payload!==null)curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode($payload,JSON_THROW_ON_ERROR)]);
 $result=curl_exec($curl);$status=curl_getinfo($curl,CURLINFO_HTTP_CODE);curl_close($curl);
 if($result===false||$status<200||$status>=300)throw new RuntimeException('Falha no provedor de pagamento.');
 return json_decode($result,true,512,JSON_THROW_ON_ERROR);
}
