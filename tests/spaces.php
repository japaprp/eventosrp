<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/spaces.php';
require dirname(__DIR__).'/src/booking.php';
$valid=['name'=>'Chácara de teste','city'=>'Ribeirão Preto','neighborhood'=>'Zona rural','address'=>'Rua de teste, 100','description'=>'Espaço com jardim e piscina.','rules'=>'Respeitar os horários de silêncio.','type'=>'Ao ar livre','capacity'=>100,'minimum_days'=>1,'cleanup_minutes'=>120,'daily_price'=>'1000.55','weekend_price'=>'1800.00','check_in_time'=>'09:00','check_out_time'=>'09:00','photos'=>['https://example.com/photo.jpg'],'events'=>['Aniversário'],'amenities'=>['Piscina','Churrasqueira','Aceita pets','TV','Wi-Fi','Ar-condicionado','Estacionamento','Cozinha','Acessibilidade','Área coberta'],'active'=>true];
$data=validateSpace($valid);
if($data['daily_price']!=='1000.55'||$data['cleanup_minutes']!==120||$data['active']!==1)throw new RuntimeException('Cadastro normalizado incorretamente.');
$cases=[['daily_price','-10'],['daily_price','100.001'],['weekend_price','0'],['capacity',0],['check_in_time','25:00'],['check_out_time','9:00'],['minimum_days',31],['cleanup_minutes',-1],['photos',['javascript:alert(1)']],['photos',[]],['photos',['http://example.com/p.jpg']],['photos',['https://user:password@example.com/p.jpg']],['events',[]],['events',[['Aniversário']]],['amenities',['Indefinido']],['active','true']];
foreach($cases as [$key,$value]){
 $input=$valid;$input[$key]=$value;$rejected=false;
 try{validateSpace($input);}catch(InvalidArgumentException){$rejected=true;}
 if(!$rejected)throw new RuntimeException('Deveria rejeitar '.$key);
}
$tooManyAmenities=$valid;$tooManyAmenities['amenities']=array_fill(0,11,'TV');$rejected=false;
try{validateSpace($tooManyAmenities);}catch(InvalidArgumentException){$rejected=true;}
if(!$rejected)throw new RuntimeException('Deveria rejeitar mais de dez comodidades.');
$ownerHash=str_repeat('a',64);$photoId=str_repeat('b',32);
$photoSet=array_map(static fn(int $index):array=>['url'=>'api.php?action=space-photo&owner='.$ownerHash.'&id='.str_pad(dechex($index+1),32,'0',STR_PAD_LEFT),'description'=>'Foto '.$index,'cover'=>$index===4],range(0,7));
$photoInput=array_replace($valid,['photos'=>$photoSet]);
$photoData=validateSpace($photoInput,$ownerHash);$savedPhotos=json_decode($photoData['photos'],true);
if(count($savedPhotos)!==8||!$savedPhotos[0]['cover']||$savedPhotos[0]['description']!=='Foto 4')throw new RuntimeException('Fotos do espaço não foram normalizadas com a principal em primeiro lugar.');
$publicRow=['id'=>1,'name'=>'Espaço','city'=>'Ribeirão Preto','neighborhood'=>'Centro','address'=>'Rua A','type'=>'Salão','description'=>'Descrição','rules'=>'','capacity'=>50,'daily_price'=>'100.00','weekend_price'=>null,'check_in_time'=>'09:00:00','check_out_time'=>'09:00:00','minimum_days'=>1,'cleanup_minutes'=>0,'active'=>1,'is_demo'=>0,'photos'=>$photoData['photos'],'event_types'=>'["Aniversário"]','amenities'=>'[]'];
$publishedPhotos=publicSpace($publicRow);
if(count($publishedPhotos['photos'])!==8||count($publishedPhotos['photoDetails'])!==8||$publishedPhotos['photos'][0]!==$savedPhotos[0]['url'])throw new RuntimeException('O catálogo público deve expor URLs e legendas das fotos em ordem principal.');
foreach([[],array_merge($photoSet,[['url'=>'https://example.com/9.jpg','description'=>'Foto 9','cover'=>false]]),array_map(static fn($photo)=>array_merge($photo,['cover'=>false]),$photoSet),array_map(static fn($photo,$index)=>array_merge($photo,['cover'=>$index<2]),$photoSet,array_keys($photoSet))] as $photos){$rejected=false;try{validateSpace(array_replace($valid,['photos'=>$photos]),$ownerHash);}catch(InvalidArgumentException){$rejected=true;}if(!$rejected)throw new RuntimeException('Deveria rejeitar quantidade ou foto principal inválida.');}
$rejected=false;try{validateSpace($photoInput,str_repeat('c',64));}catch(InvalidArgumentException){$rejected=true;}if(!$rejected)throw new RuntimeException('A foto enviada por outra conta deve ser rejeitada.');
$schedule=['check_in_time'=>'09:00:00','check_out_time'=>'09:00:00','minimum_days'=>2];
enforceSpaceSchedule($schedule,['check_in'=>'2026-10-02 09:00:00','check_out'=>'2026-10-04 09:00:00','days'=>2]);
foreach([['check_in'=>'2026-10-02 10:00:00','check_out'=>'2026-10-04 09:00:00','days'=>2],['check_in'=>'2026-10-02 09:00:00','check_out'=>'2026-10-04 10:00:00','days'=>2],['check_in'=>'2026-10-02 09:00:00','check_out'=>'2026-10-03 09:00:00','days'=>1]] as $quote){$rejected=false;try{enforceSpaceSchedule($schedule,$quote);}catch(InvalidArgumentException){$rejected=true;}if(!$rejected)throw new RuntimeException('Regra de horário/mínimo não aplicada.');}
$availabilitySpace=array_replace($valid,['daily_price'=>'1000.00','weekend_price'=>null,'check_in_time'=>'09:00','check_out_time'=>'09:00','minimum_days'=>2,'cleanup_minutes'=>60]);
$availabilityQuote=spaceAvailabilityQuote($availabilitySpace,'2026-10-02',new DateTimeImmutable('2026-10-01 09:00:00',new DateTimeZone('America/Sao_Paulo')));
if($availabilityQuote['days']!==2||$availabilityQuote['total']!=='2000.00'||$availabilityQuote['blocked_until']!=='2026-10-04 10:00:00')throw new RuntimeException('Consulta de data não considerou período mínimo, preço e preparação.');
$weekendQuote=spaceReservationQuote($availabilitySpace,'2026-10-02','2026-10-04','weekend',new DateTimeImmutable('2026-10-01 09:00:00',new DateTimeZone('America/Sao_Paulo')));
if($weekendQuote['days']!==2||$weekendQuote['total']!=='2000.00')throw new RuntimeException('Cotação de fim de semana não respeitou pacote e tarifa.');
$specialWeekend=array_replace($availabilitySpace,['weekend_price'=>'1750.00']);
$specialWeekendQuote=spaceReservationQuote($specialWeekend,'2026-10-02','2026-10-04','weekend',new DateTimeImmutable('2026-10-01 09:00:00',new DateTimeZone('America/Sao_Paulo')));
if($specialWeekendQuote['total']!=='1750.00')throw new RuntimeException('Cotação não aplicou a tarifa especial de fim de semana.');
foreach([['2026-10-04','2026-10-06','weekend'],['2026-10-02','2026-10-03','weekend'],['2026-10-02','2026-10-03','daily']] as [$checkIn,$checkOut,$plan]){$rejected=false;try{spaceReservationQuote($availabilitySpace,$checkIn,$checkOut,$plan,new DateTimeImmutable('2026-10-01 09:00:00',new DateTimeZone('America/Sao_Paulo')));}catch(InvalidArgumentException){$rejected=true;}if(!$rejected)throw new RuntimeException('Consulta do período deve respeitar pacote ou mínimo do espaço.');}
foreach(['2026-02-30','data inválida','2026-09-30'] as $date){$rejected=false;try{spaceAvailabilityQuote($availabilitySpace,$date,new DateTimeImmutable('2026-10-01 09:00:00',new DateTimeZone('America/Sao_Paulo')));}catch(InvalidArgumentException){$rejected=true;}if(!$rejected)throw new RuntimeException('Consulta deve rejeitar a data '.$date.'.');}
$end='2026-10-04 09:00:00';$blocked=(new DateTimeImmutable($end))->modify('+120 minutes')->format('Y-m-d H:i:s');
if(!bookingOverlaps('2026-10-02 09:00:00',$blocked,'2026-10-04 10:00:00','2026-10-05 10:00:00'))throw new RuntimeException('Preparação deve bloquear a próxima entrada.');
if(bookingOverlaps('2026-10-02 09:00:00',$blocked,'2026-10-04 11:00:00','2026-10-05 11:00:00'))throw new RuntimeException('Após a preparação, a entrada deve ser permitida.');
$terms=json_decode(reservationTermsSnapshot(array_replace($availabilitySpace,['id'=>9,'name'=>'Espaço de teste','city'=>'Campinas','neighborhood'=>'Centro','rules'=>'Sem som após 22h.']),$availabilityQuote,'daily',50,'Cliente Teste','2026-10-01 09:00:00'),true,512,JSON_THROW_ON_ERROR);
if($terms['host_rules']!=='Sem som após 22h.'||$terms['space']['name']!=='Espaço de teste'||$terms['space']['city']!=='Campinas'||$terms['customer_name']!=='Cliente Teste'||$terms['guests']!==50||$terms['total']!=='2000.00'||!str_contains($terms['platform_role'],'não afasta responsabilidades previstas em lei'))throw new RuntimeException('Resumo de condições precisa registrar regras, partes, espaço, cotação e escopo da plataforma.');
echo "Cadastro válido, 16 rejeições de campos, 4 verificações de horários/mínimo, 9 verificações de cotação/disponibilidade, 2 de preparação e snapshot de condições passaram.\n";
