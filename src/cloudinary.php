<?php
declare(strict_types=1);

function cloudinaryEnvironment(string $key, string $default = ''): string {
    if (function_exists('env')) return env($key, $default);
    $value = getenv($key);
    return $value === false ? $default : (string) $value;
}

function cloudinaryPhotoCloudName(): string {
    return trim(cloudinaryEnvironment('CLOUDINARY_CLOUD_NAME'));
}

function cloudinaryPhotoConfiguration(): array {
    $cloud = cloudinaryPhotoCloudName();
    $key = trim(cloudinaryEnvironment('CLOUDINARY_API_KEY'));
    $secret = trim(cloudinaryEnvironment('CLOUDINARY_API_SECRET'));
    if (!preg_match('/^[a-zA-Z0-9_-]{1,100}$/', $cloud) || $key === '' || $secret === '') {
        throw new RuntimeException('O armazenamento de fotos ainda não está configurado.');
    }
    return [$cloud, $key, $secret];
}

function cloudinaryPhotoSignature(array $parameters, string $secret): string {
    ksort($parameters, SORT_STRING);
    $parts = [];
    foreach ($parameters as $name => $value) {
        if ($value === null || $value === '') continue;
        if (!is_scalar($value)) throw new InvalidArgumentException('Parâmetro de assinatura inválido.');
        $parts[] = $name . '=' . (string) $value;
    }
    return sha1(implode('&', $parts) . $secret);
}

function cloudinarySpacePhotoPublicId(string $url, string $ownerHash): ?string {
    $cloud = cloudinaryPhotoCloudName();
    if ($cloud === '' || !preg_match('/^[a-f0-9]{64}$/D', $ownerHash)) return null;
    $parts = parse_url($url);
    if (!is_array($parts)
        || ($parts['scheme'] ?? '') !== 'https'
        || strtolower((string) ($parts['host'] ?? '')) !== 'res.cloudinary.com'
        || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])
        || isset($parts['query']) || isset($parts['fragment'])) return null;

    $prefix = '/' . $cloud . '/image/upload/';
    $path = (string) ($parts['path'] ?? '');
    if (!str_starts_with($path, $prefix)) return null;
    $asset = substr($path, strlen($prefix));
    $asset = preg_replace('/^v[0-9]+\//', '', $asset) ?? $asset;
    if (!preg_match('~^eventosrp/spaces/' . preg_quote($ownerHash, '~') . '/([a-f0-9]{32})\.jpg$~D', $asset, $match)) return null;
    return 'eventosrp/spaces/' . $ownerHash . '/' . $match[1];
}

function cloudinaryPhotoApiRequest(string $operation, array $parameters, ?CURLFile $file = null): array {
    [$cloud, $apiKey, $apiSecret] = cloudinaryPhotoConfiguration();
    if (!in_array($operation, ['upload', 'destroy'], true)) throw new InvalidArgumentException('Operação de imagem inválida.');

    $signature = cloudinaryPhotoSignature($parameters, $apiSecret);
    $fields = $parameters;
    $fields['api_key'] = $apiKey;
    $fields['signature'] = $signature;
    if ($file !== null) $fields['file'] = $file;

    if (!function_exists('curl_init')) throw new RuntimeException('A extensão cURL não está disponível no servidor.');
    $handle = curl_init('https://api.cloudinary.com/v1_1/' . $cloud . '/image/' . $operation);
    if ($handle === false) throw new RuntimeException('Não foi possível iniciar a conexão de imagens.');
    curl_setopt_array($handle, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $fields,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $raw = curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    curl_close($handle);

    if (!is_string($raw) || $status < 200 || $status >= 300) {
        throw new RuntimeException('O serviço de imagens não concluiu a operação.');
    }
    $result = json_decode($raw, true);
    if (!is_array($result)) throw new RuntimeException('O serviço de imagens retornou uma resposta inválida.');
    return $result;
}

function cloudinaryUploadSpacePhoto(array $file, string $firebaseUid): string {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new InvalidArgumentException('Selecione uma foto válida de até 2 MB.');
    }
    $temporaryPath = (string) ($file['tmp_name'] ?? '');
    $size = (int) ($file['size'] ?? 0);
    if ($temporaryPath === '' || !is_uploaded_file($temporaryPath) || $size < 1 || $size > 2 * 1024 * 1024) {
        throw new InvalidArgumentException('Cada foto pode ter até 2 MB após a otimização.');
    }
    $image = @getimagesize($temporaryPath);
    if (!$image || ($image['mime'] ?? '') !== 'image/jpeg' || $image[0] > 8192 || $image[1] > 8192) {
        throw new InvalidArgumentException('Não foi possível validar a foto otimizada.');
    }

    $ownerHash = hash('sha256', $firebaseUid);
    $publicId = 'eventosrp/spaces/' . $ownerHash . '/' . bin2hex(random_bytes(16));
    $result = cloudinaryPhotoApiRequest(
        'upload',
        ['public_id' => $publicId, 'timestamp' => time(), 'overwrite' => 'false'],
        new CURLFile($temporaryPath, 'image/jpeg', 'foto.jpg')
    );
    $url = $result['secure_url'] ?? '';
    if (!is_string($url) || cloudinarySpacePhotoPublicId($url, $ownerHash) !== $publicId) {
        throw new RuntimeException('O serviço de imagens não confirmou o endereço da foto.');
    }
    return $url;
}

function cloudinaryDeleteSpacePhoto(string $url, string $firebaseUid): void {
    $ownerHash = hash('sha256', $firebaseUid);
    $publicId = cloudinarySpacePhotoPublicId($url, $ownerHash);
    if ($publicId === null) throw new InvalidArgumentException('Esta foto não pertence à sua conta.');
    $result = cloudinaryPhotoApiRequest('destroy', ['public_id' => $publicId, 'timestamp' => time(), 'invalidate' => 'true']);
    if (!in_array($result['result'] ?? null, ['ok', 'not found'], true)) {
        throw new RuntimeException('O serviço de imagens não removeu a foto.');
    }
}
