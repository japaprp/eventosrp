<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/cloudinary.php';

$secret = 'cloudinary-test-secret';
$owner = str_repeat('a', 64);
$publicId = 'eventosrp/spaces/' . $owner . '/' . str_repeat('b', 32);
$params = ['public_id' => $publicId, 'timestamp' => 1760000000, 'overwrite' => 'false'];
$expected = sha1('overwrite=false&public_id=' . $publicId . '&timestamp=1760000000' . $secret);
if (cloudinaryPhotoSignature($params, $secret) !== $expected) throw new RuntimeException('Assinatura Cloudinary inconsistente.');

putenv('CLOUDINARY_CLOUD_NAME=reservasrp-test');
$url = 'https://res.cloudinary.com/reservasrp-test/image/upload/v1760000000/' . $publicId . '.jpg';
if (cloudinarySpacePhotoPublicId($url, $owner) !== $publicId) throw new RuntimeException('A URL do proprietário não foi reconhecida.');
if (cloudinarySpacePhotoPublicId($url, str_repeat('c', 64)) !== null) throw new RuntimeException('Uma foto de outro proprietário foi aceita.');
if (cloudinarySpacePhotoPublicId('http://res.cloudinary.com/reservasrp-test/image/upload/' . $publicId . '.jpg', $owner) !== null) throw new RuntimeException('Uma URL HTTP foi aceita.');
echo "Assinatura, URL HTTPS e isolamento de imagens por proprietário passaram.\\n";
