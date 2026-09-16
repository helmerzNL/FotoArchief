<?php

declare(strict_types=1);

$base = rtrim(getenv('SMOKE_URL') ?: 'http://127.0.0.1:8080', '/');
$codePath = getenv('SMOKE_SETUP_CODE_FILE');
$cookie = tempnam(sys_get_temp_dir(), 'foto-cookie-');
$image = tempnam(sys_get_temp_dir(), 'foto-image-');
$exitCode = 0;
$restoring = getenv('SMOKE_RESTORE') === '1';

require __DIR__.'/http-client.php';

try {
    if (! $restoring) {
        check(is_string($codePath) && is_file($codePath), 'Disposable installation code file required.');
        [$status, $body] = request('GET', '/setup');
        check($status === 200, 'Fresh setup must respond with 200.');
        [$status] = request('POST', '/setup/unlock', ['_token' => token($body), 'code' => trim(file_get_contents($codePath))]);
        check($status === 302, 'Setup unlock failed.');
        [, $body] = request('GET', '/setup');
        [$status] = request('POST', '/setup/complete', [
            '_token' => token($body),
            'db_host' => getenv('SMOKE_DB_HOST') ?: 'postgres', 'db_port' => getenv('SMOKE_DB_PORT') ?: '5432',
            'db_database' => 'fotoarchief', 'db_username' => 'fotoarchief',
            'db_password' => getenv('DB_PASSWORD') ?: 'ci-disposable-database', 'db_sslmode' => 'prefer',
            'disk' => 'local', 'path_style' => '1', 'name' => 'Release acceptance',
            'email' => 'release@example.test', 'password' => 'disposable-smoke-password',
            'password_confirmation' => 'disposable-smoke-password',
        ]);
        check($status === 302, 'Onboarding did not redirect.');
    }
    [$status] = request('GET', '/setup');
    check($status === 404, 'Installer must be closed after successful onboarding.');
    [, $body] = request('GET', '/login');
    [$status] = request('POST', '/login', [
        '_token' => token($body), 'email' => 'release@example.test', 'password' => 'disposable-smoke-password',
    ]);
    check($status === 302, 'Administrator login failed.');
    [$status, $body] = request('GET', '/admin/assets');
    check($status === 200, 'Administrator cannot open archive.');
    $csrf = token($body);
    if ($restoring) {
        check(preg_match('~(/admin/assets/[0-9A-HJKMNP-TV-Z]{26})/files/[0-9A-HJKMNP-TV-Z]{26}/media/preview300~i', $body, $assetMatch) === 1, 'Restored archive lost its processed photo.');
        $url = $assetMatch[1];
    } else {
        $bitmap = imagecreatetruecolor(120, 60);
        imagefill($bitmap, 0, 0, imagecolorallocate($bitmap, 37, 96, 148));
        imagepng($bitmap, $image);
        unset($bitmap);
        [$status, $body] = request('POST', '/admin/assets', [
            '_token' => $csrf, 'files[0]' => new CURLFile($image, 'image/png', 'acceptance.png'),
        ], true);
        check($status === 201, 'Upload acceptance failed.');
        $result = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        $url = $result['results'][0]['url'] ?? null;
    }
    check(is_string($url), 'Upload response must identify the asset detail page.');
    $detailPath = parse_url($url, PHP_URL_PATH);
    check(is_string($detailPath) && str_starts_with($detailPath, '/admin/assets/'), 'Unexpected asset link.');
    $media = null;
    for ($attempt = 0; $attempt < 60; $attempt++) {
        [$status, $body] = request('GET', $detailPath);
        check($status === 200, 'Uploaded asset not accessible to owner.');
        if (preg_match('~(?:src|href)="([^"]+/media/preview1200)"~', $body, $matches)) {
            $media = parse_url(html_entity_decode($matches[1], ENT_QUOTES), PHP_URL_PATH);
            break;
        }
        sleep(1);
    }
    check(is_string($media), 'Worker did not generate a private preview within 60 seconds.');
    [$status, $body] = request('GET', $media);
    check($status === 200 && str_starts_with($body, "\xff\xd8"), 'Private preview is not a JPEG.');
    if (! $restoring) {
        [$status] = request('POST', '/admin/assets', [
            '_token' => $csrf, 'files[0]' => new CURLFile($image, 'image/png', 'duplicate.png'),
        ], true);
        check($status === 201, 'Duplicate should be accepted into quarantine for asynchronous validation.');
    }
    [$status] = request('POST', '/logout', ['_token' => $csrf]);
    check($status === 302, 'Logout failed.');
    [$status] = request('GET', $media);
    check($status === 302, 'Anonymous access to a private preview was not denied.');
    [$status] = request('GET', '/.env');
    check(in_array($status, [403, 404], true), 'Server exposed private configuration.');
    echo $restoring ? "Restored installer lock, original account, photo preview and anonymous denial passed.\n"
        : "HTTP onboarding, login, queued upload, real JPEG and anonymous denial passed.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'Acceptance failed: '.$error->getMessage().PHP_EOL);
    $exitCode = 1;
} finally {
    unlink($cookie);
    unlink($image);
}
exit($exitCode);
