<?php
declare(strict_types=1);
function validateSpace(array $input): array {
    $data=[];$labels=['name'=>'nome','city'=>'cidade','neighborhood'=>'bairro','address'=>'endereço','description'=>'descrição','rules'=>'regras'];
    foreach(['name'=>120,'city'=>120,'neighborhood'=>120,'address'=>250,'description'=>4000,'rules'=>4000] as $field=>$max){
        $value=$input[$field]??'';
        if(!is_string($value)||mb_strlen(trim($value))>$max||(!in_array($field,['rules','neighborhood'],true)&&mb_strlen(trim($value))<2))throw new InvalidArgumentException('Confira o campo '.$labels[$field].'.');
        $data[$field]=trim($value);
    }
    if(!in_array($input['type']??null,['Salão','Ao ar livre','Corporativo','Rooftop'],true))throw new InvalidArgumentException('Escolha um tipo de espaço.');
    $data['type']=$input['type'];
    foreach(['capacity'=>[1,10000],'minimum_days'=>[1,30],'cleanup_minutes'=>[0,1440]] as $field=>[$min,$max]){
        $value=filter_var($input[$field]??null,FILTER_VALIDATE_INT);
        if($value===false||$value<$min||$value>$max)throw new InvalidArgumentException('Confira a capacidade, o período mínimo e o tempo de preparação.');
        $data[$field]=$value;
    }
    foreach(['daily_price','weekend_price'] as $field){
        $value=$input[$field]??null;
        if($field==='weekend_price'&&($value===null||$value==='')){$data[$field]=null;continue;}
        if(!is_scalar($value)||!preg_match('/^\d{1,7}(?:\.\d{1,2})?$/',(string)$value)||(float)$value<=0)throw new InvalidArgumentException('Informe valores positivos com até duas casas decimais.');
        $data[$field]=number_format((float)$value,2,'.','');
    }
    foreach(['check_in_time','check_out_time'] as $field){
        $value=$input[$field]??null;
        if(!is_string($value)||!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/',$value))throw new InvalidArgumentException('Informe os horários de entrada e saída.');
        $data[$field]=$value;
    }
    $photos=$input['photos']??[];
    if(!is_array($photos)||!array_is_list($photos)||count($photos)<1||count($photos)>5)throw new InvalidArgumentException('Informe de uma a cinco fotos por URL HTTPS.');
    foreach($photos as $url){
        if(!is_string($url)||strlen($url)>2048||!filter_var($url,FILTER_VALIDATE_URL)||strtolower(parse_url($url,PHP_URL_SCHEME)??'')!=='https'||parse_url($url,PHP_URL_USER)!==null)throw new InvalidArgumentException('As fotos devem usar URLs HTTPS válidas.');
    }
    $events=$input['events']??[];$amenities=$input['amenities']??[];
    if(!is_array($events)||!array_is_list($events)||!$events||count($events)>3)throw new InvalidArgumentException('Selecione pelo menos um tipo de evento.');
    foreach($events as $event){if(!is_string($event)||!in_array($event,['Casamento','Corporativo','Aniversário'],true))throw new InvalidArgumentException('Tipo de evento inválido.');}
    if(!is_array($amenities)||!array_is_list($amenities)||count($amenities)>8)throw new InvalidArgumentException('Confira as comodidades.');
    foreach($amenities as $value){if(!is_string($value)||!in_array($value,['Piscina','Churrasqueira','Estacionamento','Wi-Fi','Cozinha','Ar-condicionado','Acessibilidade','Área coberta'],true))throw new InvalidArgumentException('Comodidade inválida.');}
    if(!is_bool($input['active']??null))throw new InvalidArgumentException('Informe o status do anúncio.');
    $data['active']=(int)$input['active'];
    $data['photos']=json_encode($photos,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
    $data['event_types']=json_encode(array_values(array_unique($events)),JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
    $data['amenities']=json_encode(array_values(array_unique($amenities)),JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
    return $data;
}
function publicSpace(array $row): array {
    return ['id'=>(int)$row['id'],'name'=>$row['name'],'city'=>$row['city'],'neighborhood'=>$row['neighborhood'],'address'=>$row['address'],'type'=>$row['type'],'description'=>$row['description'],'rules'=>$row['rules'],'capacity'=>(int)$row['capacity'],'price'=>(float)$row['daily_price'],'weekendPrice'=>$row['weekend_price']===null?null:(float)$row['weekend_price'],'checkInTime'=>substr($row['check_in_time'],0,5),'checkOutTime'=>substr($row['check_out_time'],0,5),'minimumDays'=>(int)$row['minimum_days'],'cleanupMinutes'=>(int)$row['cleanup_minutes'],'active'=>(bool)$row['active'],'isDemo'=>(bool)$row['is_demo'],'photos'=>json_decode($row['photos']??'[]',true),'events'=>json_decode($row['event_types']??'["Casamento","Corporativo","Aniversário"]',true),'amenities'=>json_decode($row['amenities']??'[]',true)];
}
function enforceSpaceSchedule(array $space,array $quote): void {
    if(substr($quote['check_in'],11,5)!==substr($space['check_in_time'],0,5)||substr($quote['check_out'],11,5)!==substr($space['check_out_time'],0,5))throw new InvalidArgumentException('Use os horários de entrada e saída definidos pelo espaço.');
    if($quote['days']<(int)$space['minimum_days'])throw new InvalidArgumentException('Este espaço exige no mínimo '.$space['minimum_days'].' diárias.');
}
