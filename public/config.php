<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if(!is_file(dirname(__DIR__).'/vendor/autoload.php')){echo '{"firebase":null}';exit;}
require dirname(__DIR__).'/src/bootstrap.php';
respond(['firebase'=>['apiKey'=>env('FIREBASE_API_KEY'),'authDomain'=>env('FIREBASE_AUTH_DOMAIN'),'projectId'=>env('FIREBASE_PROJECT_ID'),'databaseURL'=>env('FIREBASE_DATABASE_URL'),'appId'=>env('FIREBASE_APP_ID')]]);
