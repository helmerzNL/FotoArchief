<?php

declare(strict_types=1);

/**
 * Release acceptance for the exchange module against a running deployment.
 *
 * This repeats over real HTTP, against a worker that is already running, the
 * flow that was verified by hand before the module was integrated: a CSV dry
 * run that changes nothing, an explicit confirmation, and metadata and package
 * exports that are released only through a personal short-lived link.
 *
 * It deliberately touches nothing it did not create. The photo is generated
 * here, and the CSV it imports is that same photo's own export, so the harness
 * can run against a deployment that already holds data without editing any of
 * it. It never resets onboarding and never reads a real credential: the account
 * is the disposable one created by tests/Smoke/http-onboarding.php.
 */
$base = rtrim(getenv('SMOKE_URL') ?: 'http://127.0.0.1:8080', '/');
$email = getenv('SMOKE_EMAIL') ?: 'release@example.test';
$password = getenv('SMOKE_PASSWORD') ?: 'disposable-smoke-password';
$budget = max(30, (int) (getenv('SMOKE_EXCHANGE_TIMEOUT') ?: 300));
$cookie = tempnam(sys_get_temp_dir(), 'foto-cookie-');
$image = tempnam(sys_get_temp_dir(), 'foto-image-');
$csvFile = tempnam(sys_get_temp_dir(), 'foto-csv-');
$archive = tempnam(sys_get_temp_dir(), 'foto-zip-');
$exitCode = 0;

require __DIR__.'/http-client.php';

/**
 * The shared client returns only a status and a body. Exchange hands out its
 * download link as a redirect and then streams bytes, so these two helpers add
 * header access here rather than changing the contract the other harnesses use.
 *
 * @param  array<string, mixed>  $data
 */
function redirectTarget(string $path, array $data): string
{
    global $base, $cookie;
    $curl = curl_init($base.$path);
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_COOKIEJAR => $cookie,
        CURLOPT_COOKIEFILE => $cookie,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $data,
        CURLOPT_TIMEOUT => 120,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER => ['Accept: text/html'],
    ]);
    $response = curl_exec($curl);
    $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $headerSize = (int) curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    check(is_string($response), 'HTTP transport failed for '.$path.'.');
    check($status === 302, 'Expected a redirect from '.$path.', got '.$status.'.');
    check(preg_match('~^location:\s*(\S+)~mi', substr($response, 0, $headerSize), $matches) === 1, 'Redirect from '.$path.' carried no location.');
    $target = parse_url(trim($matches[1]), PHP_URL_PATH);
    check(is_string($target) && $target !== '', 'Redirect from '.$path.' had no usable path.');

    return $target;
}

/**
 * @return array{0: int, 1: array<string, string>, 2: string}
 */
function fetchFile(string $path): array
{
    global $base, $cookie;
    $curl = curl_init($base.$path);
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_COOKIEJAR => $cookie,
        CURLOPT_COOKIEFILE => $cookie,
        CURLOPT_TIMEOUT => 300,
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    $response = curl_exec($curl);
    $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $headerSize = (int) curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    check(is_string($response), 'HTTP transport failed for '.$path.'.');
    $headers = [];
    foreach (explode("\n", substr($response, 0, $headerSize)) as $line) {
        if (str_contains($line, ':')) {
            [$name, $value] = explode(':', $line, 2);
            $headers[strtolower(trim($name))] = trim($value);
        }
    }

    return [$status, $headers, substr($response, $headerSize)];
}

/**
 * Both exchange pages state their state in one intro line, which is what makes
 * a status readable without an API. Matching that line rather than the whole
 * page keeps the summary counters from being mistaken for a failure.
 */
function statusLine(string $html): string
{
    return preg_match('~<p class="intro">(.*?)</p>~s', $html, $matches) === 1 ? html_entity_decode(trim($matches[1]), ENT_QUOTES) : '';
}

/**
 * Waits for a queued run to settle. The work happens in an external worker, so
 * a timeout has to say which side stalled instead of hanging the release gate.
 *
 * @param  list<string>  $done
 * @param  list<string>  $failed
 */
function waitForStatus(string $path, array $done, array $failed, int $seconds, string $what): string
{
    $deadline = time() + $seconds;
    $seen = '';
    do {
        [$status, $body] = request('GET', $path);
        check($status === 200, $what.' page returned '.$status.'.');
        $seen = statusLine($body);
        foreach ($failed as $label) {
            check(! str_contains($seen, $label), $what.' failed on the server: '.$seen);
        }
        foreach ($done as $label) {
            if (str_contains($seen, $label)) {
                return $body;
            }
        }
        sleep(2);
    } while (time() < $deadline);

    throw new RuntimeException($what.' stalled at "'.$seen.'" after '.$seconds.' seconds. Is a worker consuming the ingest queue?');
}

function formField(string $html, string $name): string
{
    check(preg_match('/name="'.preg_quote($name, '/').'"\s+value="([^"]*)"/', $html, $matches) === 1, 'Form field '.$name.' is missing.');

    return html_entity_decode($matches[1], ENT_QUOTES);
}

/**
 * No exchange page may ever show where an artifact physically lives: the whole
 * point of the module is that private media leaves only through a checked link.
 */
function assertNoStorageLeak(string $haystack, string $what): void
{
    foreach (['storage_key', 'storage_disk', 'app/private', 'storage/app', 'quarantine'] as $needle) {
        check(! str_contains($haystack, $needle), $what.' exposed a private storage location: '.$needle.'.');
    }
}

/**
 * Requests one export, waits for the worker, downloads it through a freshly
 * issued personal link, and proves the bytes match the published checksum.
 *
 * @return array{0: string, 1: string}
 */
function exportAndDownload(string $type, string $assetId, string $contentType, int $budget): array
{
    [$status, $body] = request('GET', '/exchange');
    check($status === 200, 'Exchange page is not available.');
    $exportPath = redirectTarget('/exchange/exports', [
        '_token' => token($body),
        'export_type' => $type,
        'scope' => 'selection',
        'asset_ids[]' => $assetId,
    ]);
    check(str_starts_with($exportPath, '/exchange/exports/'), 'Export request did not open an export page.');

    $page = waitForStatus($exportPath, ['Klaar om te downloaden'], ['Mislukt', 'Verlopen', 'Ingetrokken'], $budget, 'Export '.$type);
    check(preg_match('~<span class="checksum">([a-f0-9]{64})</span>~', $page, $matches) === 1, 'Export '.$type.' published no checksum.');
    assertNoStorageLeak($page, 'The export page');

    $downloadPath = redirectTarget($exportPath.'/link', ['_token' => token($page)]);
    check(preg_match('~^/exchange/exports/[0-9A-Za-z]+/download/[a-f0-9]{64}$~', $downloadPath) === 1, 'The download link is not a tokenised URL: '.$downloadPath);

    [$status, $headers, $bytes] = fetchFile($downloadPath);
    check($status === 200, 'Download of '.$type.' returned '.$status.'.');
    check(str_starts_with($headers['content-type'] ?? '', $contentType), 'Download of '.$type.' served "'.($headers['content-type'] ?? 'no type').'" instead of '.$contentType.'.');
    check(str_contains($headers['content-disposition'] ?? '', 'attachment'), 'Download of '.$type.' was not served as an attachment.');
    check(hash('sha256', $bytes) === $matches[1], 'Downloaded bytes of '.$type.' do not match the published checksum.');

    return [$downloadPath, $bytes];
}

try {
    check(extension_loaded('zip'), 'The zip extension is required to verify a package export.');
    check(extension_loaded('gd'), 'The gd extension is required to generate the fixture photo.');

    [, $body] = request('GET', '/login');
    [$status] = request('POST', '/login', ['_token' => token($body), 'email' => $email, 'password' => $password]);
    check($status === 302, 'Login failed for the disposable acceptance account.');

    // A photo made here, so the harness never edits data it did not create.
    [$status, $body] = request('GET', '/admin/assets');
    check($status === 200, 'The archive is not available.');
    $bitmap = imagecreatetruecolor(160, 90);
    imagefill($bitmap, 0, 0, (int) imagecolorallocate($bitmap, 52, 111, 86));
    // The archive holds a byte-identical upload back as a duplicate, which is
    // correct and is why every run has to bring its own photo: a fixed fixture
    // would be processed on the first run and quietly never again.
    for ($pixel = 0; $pixel < 64; $pixel++) {
        imagesetpixel($bitmap, random_int(0, 159), random_int(0, 89), (int) imagecolorallocate($bitmap, random_int(0, 255), random_int(0, 255), random_int(0, 255)));
    }
    imagepng($bitmap, $image);
    [$status, $body] = request('POST', '/admin/assets', [
        '_token' => token($body),
        'files[0]' => new CURLFile($image, 'image/png', 'exchange-acceptance.png'),
    ], true);
    check($status === 201, 'Upload of the exchange fixture failed with status '.$status.'.');
    $upload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    check(is_array($upload), 'The upload response was not readable.');
    $detailPath = parse_url((string) ($upload['results'][0]['url'] ?? ''), PHP_URL_PATH);
    check(is_string($detailPath) && str_starts_with($detailPath, '/admin/assets/'), 'The upload response identified no photo.');
    $assetId = basename($detailPath);

    // A package export must carry derivatives, so wait for the same worker the
    // onboarding harness waits for before asking for anything.
    $deadline = time() + $budget;
    do {
        [$status, $detail] = request('GET', $detailPath);
        check($status === 200, 'The photo page returned '.$status.'.');
        $processed = str_contains($detail, 'preview1200');
        if (! $processed) {
            sleep(2);
        }
    } while (! $processed && time() < $deadline);
    check($processed, 'The fixture photo was not processed within '.$budget.' seconds. Is a worker consuming the ingest queue?');

    // A round trip: the CSV that is imported is this photo's own export, so the
    // column contract is exercised for real and no other row is ever touched.
    [, $csvBytes] = exportAndDownload('metadata_csv', $assetId, 'text/csv', $budget);
    $lines = array_values(array_filter(explode("\n", str_replace("\r\n", "\n", $csvBytes)), static fn (string $line): bool => trim($line) !== ''));
    check(count($lines) === 2, 'The metadata CSV should hold exactly the one exported photo, got '.count($lines).' lines.');
    $header = str_getcsv($lines[0], ',', '"', '\\');
    $values = str_getcsv($lines[1], ',', '"', '\\');
    check(($header[0] ?? '') === 'accession_number' && ($header[1] ?? '') === 'lock_version', 'Unexpected CSV column order.');
    $accession = (string) ($values[0] ?? '');
    $version = (string) ($values[1] ?? '');
    check($accession !== '' && $version !== '', 'The exported CSV carried no accession number or version.');

    $marker = 'Acceptatietitel '.date('YmdHis');
    file_put_contents($csvFile, "accession_number,lock_version,title\n".'"'.$accession.'","'.$version.'","'.$marker."\"\n");

    [$status, $body] = request('GET', '/exchange');
    check($status === 200, 'Exchange page is not available.');
    $importPath = redirectTarget('/exchange/imports', [
        '_token' => token($body),
        'write_mode' => 'overwrite',
        'file' => new CURLFile($csvFile, 'text/csv', 'acceptance.csv'),
    ]);
    check(str_starts_with($importPath, '/exchange/imports/'), 'The upload did not open an import page.');

    // The dry run: the planned change is shown, and nothing is written yet.
    $page = waitForStatus($importPath, ['Gecontroleerd, wacht op bevestiging'], ['Mislukt'], $budget, 'Import check');
    check(str_contains($page, 'Klaar om bij te werken: 1'), 'The dry run did not plan exactly one row.');
    check(str_contains($page, $marker), 'The dry run did not show the proposed new title.');
    check(str_contains($page, 'Bijgewerkt: 0'), 'The dry run reported applied rows before it was confirmed.');
    [$status, $detail] = request('GET', $detailPath);
    check($status === 200, 'The photo page is not available during the dry run.');
    check(! str_contains($detail, $marker), 'The dry run changed the photo before it was confirmed.');

    [$status] = request('POST', $importPath.'/confirm', ['_token' => token($page), 'checksum' => formField($page, 'checksum')]);
    check($status === 302, 'The import confirmation was refused with status '.$status.'.');
    $page = waitForStatus($importPath, ['Afgerond'], ['Mislukt'], $budget, 'Import run');
    check(str_contains($page, 'Bijgewerkt: 1'), 'The confirmed import did not report one updated row.');

    [$status, $detail] = request('GET', $detailPath);
    check($status === 200 && str_contains($detail, $marker), 'The confirmed import did not reach the photo.');

    [, $jsonBytes] = exportAndDownload('metadata_json', $assetId, 'application/json', $budget);
    $metadata = json_decode($jsonBytes, true, 512, JSON_THROW_ON_ERROR);
    check(is_array($metadata), 'The metadata export was not readable JSON.');
    check(($metadata['assets'][0]['title'] ?? null) === $marker, 'The metadata export does not show the imported title.');
    assertNoStorageLeak($jsonBytes, 'The metadata export');

    [$downloadPath, $zipBytes] = exportAndDownload('package_zip', $assetId, 'application/zip', $budget);
    file_put_contents($archive, $zipBytes);
    $zip = new ZipArchive;
    check($zip->open($archive) === true, 'The package export is not a readable ZIP.');
    $manifest = json_decode((string) $zip->getFromName('manifest.json'), true, 512, JSON_THROW_ON_ERROR);
    check(is_array($manifest) && ($manifest['asset_count'] ?? 0) === 1, 'The manifest does not describe exactly the exported photo.');
    check(($manifest['skipped'] ?? []) === [], 'The manifest skipped a photo that should have been exportable.');
    $sums = (string) $zip->getFromName('checksums.sha256');
    check($sums !== '', 'The package export carries no checksum file.');
    $verified = 0;
    foreach (explode("\n", trim($sums)) as $line) {
        if (trim($line) === '') {
            continue;
        }
        $parts = preg_split('/\s+/', trim($line), 2);
        check(is_array($parts) && count($parts) === 2, 'A checksum line could not be read: '.$line);
        $entry = $zip->getFromName($parts[1]);
        check(is_string($entry), 'The checksum file names a missing entry: '.$parts[1].'.');
        check(hash('sha256', $entry) === $parts[0], 'Checksum mismatch inside the package for '.$parts[1].'.');
        $verified++;
    }
    $names = [];
    for ($index = 0; $index < $zip->numFiles; $index++) {
        $names[] = (string) $zip->getNameIndex($index);
    }
    $zip->close();
    $listing = implode("\n", $names);
    check($verified >= 3, 'The package export verified suspiciously few files: '.$verified.'.');
    check(in_array('metadata.json', $names, true) && in_array('metadata.csv', $names, true), 'The package export is missing its metadata.');
    check(str_contains($listing, 'originals/'), 'The package export contains no original.');
    check(str_contains($listing, 'derivatives/'), 'The package export contains no derivative.');
    assertNoStorageLeak($listing, 'The package listing');

    // The link is personal: once the session is gone it has to stop working.
    [, $body] = request('GET', '/exchange');
    [$status] = request('POST', '/logout', ['_token' => token($body)]);
    check($status === 302, 'Logout failed.');
    [$status, $body] = request('GET', $downloadPath);
    check($status === 302, 'An anonymous visitor was not denied a download link, status '.$status.'.');
    check(! str_contains($body, 'PK'), 'An anonymous visitor received archive bytes.');

    echo 'Exchange acceptance passed: the dry run changed nothing, the confirmed import applied one row, '
        .'and the JSON, CSV and ZIP exports downloaded with matching checksums ('.$verified." files verified in the package).\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'Exchange acceptance failed: '.$error->getMessage().PHP_EOL);
    $exitCode = 1;
} finally {
    foreach ([$cookie, $image, $csvFile, $archive] as $path) {
        if (is_string($path) && is_file($path)) {
            unlink($path);
        }
    }
}
exit($exitCode);
