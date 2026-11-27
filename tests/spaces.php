<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/cloudinary.php';
require dirname(__DIR__) . '/src/spaces.php';
require dirname(__DIR__) . '/src/booking.php';

$valid = [
    'name' => 'Chácara de teste',
    'city' => 'Ribeirão Preto',
    'neighborhood' => 'Zona rural',
    'address' => 'Rua de teste, 100',
    'description' => 'Espaço com jardim e piscina.',
    'rules' => 'Respeitar os horários de silêncio.',
    'type' => 'Ao ar livre',
    'capacity' => 100,
    'minimum_days' => 1,
    'cleanup_minutes' => 120,
    'daily_price' => '1000.55',
    'weekend_price' => '1800.00',
    'check_in_time' => '09:00',
    'check_out_time' => '09:00',
    'photos' => ['https://example.com/photo.jpg'],
    'events' => ['Aniversário'],
    'amenities' => ['Piscina', 'Churrasqueira'],
    'active' => true,
    'owner_authorized' => true,
];
$data = validateSpace($valid, hash('sha256', 'owner-a'));
if ($data['daily_price'] !== '1000.55' || $data['cleanup_minutes'] !== 120 || $data['active'] !== 1) {
    throw new RuntimeException('Cadastro normalizado incorretamente.');
}
if ($data['owner_authorization_version'] !== SPACE_AUTHORIZATION_VERSION || $data['owner_authorization_snapshot'] !== SPACE_AUTHORIZATION_TEXT || !$data['owner_authorized_at']) {
    throw new RuntimeException('A autorização do proprietário não foi registrada.');
}

if (spaceOwnerPublicationState('pending', true) !== 0) {
    throw new RuntimeException('A solicitação de publicação não pode ativar o anúncio antes da aprovação administrativa.');
}
if (spaceOwnerPublicationState('rejected', true) !== 0 || spaceOwnerPublicationState('none', true) !== 0) {
    throw new RuntimeException('Somente proprietários aprovados podem publicar espaços.');
}
if (spaceOwnerPublicationState('approved', false) !== 0 || spaceOwnerPublicationState('approved', true) !== 1) {
    throw new RuntimeException('O proprietário aprovado deve poder manter o anúncio pausado ou solicitar sua publicação.');
}

$cases = [
    ['daily_price', '-10'], ['daily_price', '100.001'], ['weekend_price', '0'],
    ['capacity', 0], ['check_in_time', '25:00'], ['check_out_time', '9:00'],
    ['minimum_days', 31], ['cleanup_minutes', -1], ['photos', ['javascript:alert(1)']],
    ['photos', []], ['photos', ['http://example.com/p.jpg']],
    ['photos', ['https://user:password@example.com/p.jpg']], ['events', []],
    ['events', [['Aniversário']]], ['amenities', ['Indefinido']], ['active', 'true'],
    ['owner_authorized', false],
];
foreach ($cases as [$key, $value]) {
    $input = $valid;
    $input[$key] = $value;
    $rejected = false;
    try { validateSpace($input, hash('sha256', 'owner-a')); } catch (InvalidArgumentException) { $rejected = true; }
    if (!$rejected) throw new RuntimeException('Deveria rejeitar ' . $key);
}

$ownerHash = hash('sha256', 'owner-a');
putenv('CLOUDINARY_CLOUD_NAME=reservasrp-test');
$assetId = 'eventosrp/spaces/' . $ownerHash . '/' . str_repeat('b', 32);
$cloudinaryUrl = 'https://res.cloudinary.com/reservasrp-test/image/upload/v1760000000/' . $assetId . '.jpg';
$cloudinaryInput = $valid;
$cloudinaryInput['photos'] = [$cloudinaryUrl];
validateSpace($cloudinaryInput, $ownerHash);
$rejected = false;
try { validateSpace($cloudinaryInput, hash('sha256', 'owner-b')); } catch (InvalidArgumentException) { $rejected = true; }
if (!$rejected) throw new RuntimeException('Foto de outro proprietário foi aceita.');

$schedule = ['check_in_time' => '09:00:00', 'check_out_time' => '09:00:00', 'minimum_days' => 2];
enforceSpaceSchedule($schedule, ['check_in' => '2026-10-02 09:00:00', 'check_out' => '2026-10-04 09:00:00', 'days' => 2]);
foreach ([
    ['check_in' => '2026-10-02 10:00:00', 'check_out' => '2026-10-04 09:00:00', 'days' => 2],
    ['check_in' => '2026-10-02 09:00:00', 'check_out' => '2026-10-04 10:00:00', 'days' => 2],
    ['check_in' => '2026-10-02 09:00:00', 'check_out' => '2026-10-03 09:00:00', 'days' => 1],
] as $quote) {
    $rejected = false;
    try { enforceSpaceSchedule($schedule, $quote); } catch (InvalidArgumentException) { $rejected = true; }
    if (!$rejected) throw new RuntimeException('Regra de horário/mínimo não aplicada.');
}
$end = '2026-10-04 09:00:00';
$blocked = (new DateTimeImmutable($end))->modify('+120 minutes')->format('Y-m-d H:i:s');
if (!bookingOverlaps('2026-10-02 09:00:00', $blocked, '2026-10-04 10:00:00', '2026-10-05 10:00:00')) throw new RuntimeException('Preparação deve bloquear a próxima entrada.');
if (bookingOverlaps('2026-10-02 09:00:00', $blocked, '2026-10-04 11:00:00', '2026-10-05 11:00:00')) throw new RuntimeException('Após a preparação, a entrada deve ser permitida.');
echo "Cadastro, autorização, validação de fotos, horários, preparação e isolamento de proprietário passaram.\\n";