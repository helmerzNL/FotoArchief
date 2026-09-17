<?php

declare(strict_types=1);

use Aws\S3\S3Client;
use Symfony\Component\Process\Process;

require __DIR__.'/release-fixture.php';

if (getenv('FOTOARCHIEF_DISPOSABLE_S3_RECOVERY') !== '1' || $argc !== 2) {
    throw new RuntimeException('Use FOTOARCHIEF_DISPOSABLE_S3_RECOVERY=1 and supply one production ZIP. This harness creates its own isolated services.');
}
$pgBin = rtrim((string) getenv('FOTOARCHIEF_TEST_PG_BIN'), '/\\');
$weed = (string) getenv('FOTOARCHIEF_TEST_WEED_BIN');
$suffix = PHP_OS_FAMILY === 'Windows' ? '.exe' : '';
foreach (['initdb', 'postgres', 'pg_isready', 'createdb', 'pg_dump', 'pg_restore', 'pg_ctl'] as $binary) {
    if (! is_file($pgBin.'/'.$binary.$suffix)) {
        throw new RuntimeException('Missing PostgreSQL test binary: '.$binary);
    }
}
if (! is_file($weed)) {
    throw new RuntimeException('Supply the pinned SeaweedFS test binary, not a live S3 endpoint.');
}
$root = sys_get_temp_dir().'/fotoarchief-s3-recovery-'.bin2hex(random_bytes(8));
$installed = $root.'/installed';
mkdir($installed, 0700, true);
$server = $s3 = $postgres = null;
$pgData = null;
$database = 'fotoarchief_release_test';
$pgPort = (int) parse_url('tcp://'.releaseLoopbackAddress(), PHP_URL_PORT);
$s3Port = 23443;
$bucket = 'fotoarchief-recovery-test';
$access = 'disposable-recovery-access';
$secret = bin2hex(random_bytes(24));
$processEnv = ['PGPASSWORD' => 'disposable-fixture-password'];

function recoveryCommand(array $command, string $root, array $environment = []): void
{
    (new Process($command, $root, $environment, timeout: 300))
        ->mustRun(static function (string $type, string $output): void {
            fwrite($type === Process::ERR ? STDERR : STDOUT, $output);
        });
}

function recoveryObjectSnapshot(S3Client $client, string $bucket, string $directory): array
{
    mkdir($directory, 0700);
    $inventory = $client->listObjectsV2(['Bucket' => $bucket, 'MaxKeys' => 21]);
    $entries = $inventory['Contents'] ?? [];
    if ($entries === [] || count($entries) > 20 || ($inventory['IsTruncated'] ?? false)
        || array_sum(array_column($entries, 'Size')) > 33554432) {
        throw new RuntimeException('Recovery fixture must contain 1-20 objects and at most 32 MiB.');
    }
    $manifest = [];
    foreach ($entries as $entry) {
        $object = $client->getObject(['Bucket' => $bucket, 'Key' => $entry['Key'], 'IfMatch' => $entry['ETag']]);
        $bytes = (string) $object['Body'];
        $filename = hash('sha256', $entry['Key']).'.bin';
        if (file_put_contents($directory.'/'.$filename, $bytes) !== strlen($bytes)) {
            throw new RuntimeException('Cannot persist recovery object.');
        }
        $manifest[] = ['key' => $entry['Key'], 'file' => $filename, 'sha256' => hash('sha256', $bytes),
            'size' => strlen($bytes), 'content_type' => $object['ContentType'] ?? 'application/octet-stream'];
    }
    file_put_contents($directory.'/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

    return $manifest;
}

function restoreRecoveryObjects(S3Client $client, string $bucket, string $directory): void
{
    if (($client->listObjectsV2(['Bucket' => $bucket, 'MaxKeys' => 1])['Contents'] ?? []) !== []) {
        throw new RuntimeException('Recovery target bucket must be empty.');
    }
    $manifest = json_decode(file_get_contents($directory.'/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
    if (! is_array($manifest) || $manifest === []) {
        throw new RuntimeException('Missing recovery manifest.');
    }
    foreach ($manifest as $entry) {
        if (! is_array($entry) || ! is_string($entry['file'] ?? null)
            || ! preg_match('/^[a-f0-9]{64}\.bin$/D', $entry['file'])
            || hash_file('sha256', $directory.'/'.$entry['file']) !== ($entry['sha256'] ?? null)
            || filesize($directory.'/'.$entry['file']) !== ($entry['size'] ?? null)) {
            throw new RuntimeException('Recovery object checksum validation failed.');
        }
    }
    foreach ($manifest as $entry) {
        $client->putObject(['Bucket' => $bucket, 'Key' => $entry['key'], 'ACL' => 'private',
            'Body' => file_get_contents($directory.'/'.$entry['file']), 'ContentType' => $entry['content_type']]);
        $bytes = (string) $client->getObject(['Bucket' => $bucket, 'Key' => $entry['key']])['Body'];
        if (hash('sha256', $bytes) !== $entry['sha256']) {
            throw new RuntimeException('Restored S3 bytes do not match backup.');
        }
    }
}

try {
    echo "Extracting the production package for S3 recovery.\n";
    $version = extractRelease($argv[1], $installed);
    require $installed.'/vendor/autoload.php';
    $environment = [
        'APP_ENV' => 'production', 'APP_DEBUG' => 'false', 'INGEST_SCANNER' => 'none',
        'APP_URL' => 'http://'.releaseLoopbackAddress(), 'SMOKE_DB_HOST' => '127.0.0.1',
        'SMOKE_DB_PORT' => (string) $pgPort, 'SMOKE_DB_DATABASE' => $database,
        'SMOKE_DB_USER' => 'release_fixture', 'DB_PASSWORD' => 'disposable-fixture-password',
        'SMOKE_SETUP_CODE_FILE' => $installed.'/storage/app/installation/setup-code.txt',
        'SMOKE_STORAGE_DISK' => 's3', 'SMOKE_S3_ENDPOINT' => 'http://127.0.0.1:'.$s3Port,
        'SMOKE_S3_REGION' => 'us-east-1', 'SMOKE_S3_BUCKET' => $bucket,
        'SMOKE_S3_ACCESS_KEY' => $access, 'SMOKE_S3_SECRET_KEY' => $secret, 'SMOKE_RESTORE' => '0',
    ];
    $environment['SMOKE_URL'] = $environment['APP_URL'];
    $client = new S3Client(['version' => 'latest', 'region' => 'us-east-1',
        'endpoint' => $environment['SMOKE_S3_ENDPOINT'], 'use_path_style_endpoint' => true,
        'credentials' => ['key' => $access, 'secret' => $secret],
        'http' => ['connect_timeout' => 3, 'timeout' => 10]]);
    $auth = $root.'/s3-auth.json';
    file_put_contents($auth, json_encode(['identities' => [[
        'name' => 'disposable-recovery', 'credentials' => [['accessKey' => $access, 'secretKey' => $secret]],
        'actions' => ['Admin', 'Read', 'Write', 'List', 'Tagging'],
    ]]], JSON_THROW_ON_ERROR));
    $startPostgres = static function (string $data) use ($pgBin, $suffix, $pgPort, $database, $root, $processEnv): Process {
        recoveryCommand([$pgBin.'/initdb'.$suffix, '-D', $data, '-U', 'release_fixture', '--auth=trust', '--encoding=UTF8', '--locale=C'], $root);
        $process = new Process([$pgBin.'/postgres'.$suffix, '-D', $data, '-h', '127.0.0.1', '-p', (string) $pgPort,
            '-c', 'unix_socket_directories='], $root, timeout: null);
        $process->start();
        try {
            for ($attempt = 0; $attempt < 100; $attempt++) {
                $ready = new Process([$pgBin.'/pg_isready'.$suffix, '-h', '127.0.0.1', '-p', (string) $pgPort], $root);
                if ($ready->run() === 0) {
                    recoveryCommand([$pgBin.'/createdb'.$suffix, '-h', '127.0.0.1', '-p', (string) $pgPort, '-U', 'release_fixture', $database], $root, $processEnv);

                    return $process;
                }
                if (! $process->isRunning()) {
                    throw new RuntimeException('Isolated PostgreSQL failed: '.$process->getErrorOutput());
                }
                usleep(100000);
            }
            throw new RuntimeException('Isolated PostgreSQL did not become ready.');
        } catch (Throwable $error) {
            $process->stop();
            throw $error;
        }
    };
    $startS3 = static function (string $directory) use ($weed, $root, $auth, $s3Port, $client, $bucket): Process {
        foreach ([23440, 23441, 23442, 23443, 23444, 33440, 33441, 33442, 33443, 33444] as $port) {
            $socket = @stream_socket_server('tcp://127.0.0.1:'.$port);
            if ($socket === false) {
                throw new RuntimeException('Disposable S3 port is already occupied: '.$port);
            }
            fclose($socket);
        }
        mkdir($directory, 0700);
        $process = new Process([$weed, 'mini', '-dir='.$directory, '-ip=127.0.0.1', '-ip.bind=127.0.0.1',
            '-master.port=23440', '-volume.port=23441', '-filer.port=23442', '-s3.port='.$s3Port,
            '-admin.port=23444', '-admin.ui=false', '-webdav=false', '-master.telemetry=false',
            '-s3.port.iceberg=0', '-s3.port.lance=0', '-volume.max=4', '-s3.config='.$auth], $root, timeout: null);
        $process->start();
        try {
            for ($attempt = 0; $attempt < 150; $attempt++) {
                $curl = curl_init('http://127.0.0.1:'.$s3Port.'/');
                curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 1, CURLOPT_NOPROXY => '*']);
                curl_exec($curl);
                if (curl_getinfo($curl, CURLINFO_RESPONSE_CODE) === 403) {
                    $client->createBucket(['Bucket' => $bucket, 'ACL' => 'private']);

                    return $process;
                }
                if (! $process->isRunning()) {
                    throw new RuntimeException('Isolated S3 failed: '.$process->getErrorOutput());
                }
                usleep(100000);
            }
            throw new RuntimeException('Isolated private S3 did not become ready.');
        } catch (Throwable $error) {
            $process->stop();
            throw $error;
        }
    };

    $pgData = $root.'/postgres-source';
    echo "Initializing isolated source PostgreSQL and S3 services.\n";
    $postgres = $startPostgres($pgData);
    $s3 = $startS3($root.'/s3-source');
    releaseArtisan($installed, $environment, ['installation:prepare', '--quiet-code']);
    $server = startReleaseServer($installed, $environment);
    runReleaseOnboarding($installed, $environment);
    $server->stop();
    releaseArtisan($installed, $environment, ['queue:work', 'ingest', '--stop-when-empty', '--tries=3']);
    $snapshot = privateSnapshot($installed);
    recoveryCommand([$pgBin.'/pg_dump'.$suffix, '-h', '127.0.0.1', '-p', (string) $pgPort, '-U', 'release_fixture',
        '--format=custom', '--no-owner', '--no-acl', '--file='.$root.'/database.dump', $database], $root, $processEnv);
    $manifest = recoveryObjectSnapshot($client, $bucket, $root.'/objects');
    if (count($manifest) < 4) {
        throw new RuntimeException('Original and generated derivatives were not stored in S3.');
    }
    $s3->stop();
    recoveryCommand([$pgBin.'/pg_ctl'.$suffix, '-D', $pgData, '-m', 'fast', '-w', 'stop'], $root);
    $postgres->stop();
    if (! rename($installed.'/storage/app', $root.'/app-backup')) {
        throw new RuntimeException('Cannot isolate source installation state.');
    }
    mkdir($installed.'/storage/app', 0700);

    echo "Restoring into newly initialized PostgreSQL, S3 and private application storage.\n";
    $pgData = $root.'/postgres-target';
    $postgres = $startPostgres($pgData);
    $s3 = $startS3($root.'/s3-target');
    $pdo = new PDO("pgsql:host=127.0.0.1;port={$pgPort};dbname={$database}", 'release_fixture', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    if ((int) $pdo->query("SELECT count(*) FROM pg_tables WHERE schemaname = 'public'")->fetchColumn() !== 0
        || ($client->listObjectsV2(['Bucket' => $bucket])['Contents'] ?? []) !== []) {
        throw new RuntimeException('Restore requires a genuinely empty database and S3 service.');
    }
    $payload = $root.'/objects/'.$manifest[0]['file'];
    $original = file_get_contents($payload);
    file_put_contents($payload, 'corrupted recovery fixture');
    try {
        restoreRecoveryObjects($client, $bucket, $root.'/objects');
        $anonymous = curl_init($environment['SMOKE_S3_ENDPOINT'].'/'.$bucket.'/'.implode('/', array_map('rawurlencode', explode('/', $manifest[0]['key']))));
        curl_setopt_array($anonymous, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5, CURLOPT_NOPROXY => '*']);
        curl_exec($anonymous);
        if (curl_getinfo($anonymous, CURLINFO_RESPONSE_CODE) !== 403) {
            throw new RuntimeException('Restored S3 object permits anonymous access.');
        }
        throw new LogicException('Corrupt recovery snapshot was accepted.');
    } catch (RuntimeException $error) {
        if ($error->getMessage() !== 'Recovery object checksum validation failed.') {
            throw $error;
        }
    }
    if (($client->listObjectsV2(['Bucket' => $bucket])['Contents'] ?? []) !== []) {
        throw new RuntimeException('Corrupt snapshot partially changed the target.');
    }
    file_put_contents($payload, $original);
    restoreRecoveryObjects($client, $bucket, $root.'/objects');
    try {
        restoreRecoveryObjects($client, $bucket, $root.'/objects');
        throw new LogicException('Nonempty target was overwritten.');
    } catch (RuntimeException $error) {
        if ($error->getMessage() !== 'Recovery target bucket must be empty.') {
            throw $error;
        }
    }
    recoveryCommand([$pgBin.'/pg_restore'.$suffix, '-h', '127.0.0.1', '-p', (string) $pgPort, '-U', 'release_fixture',
        '--no-owner', '--no-acl', '--single-transaction', '--exit-on-error', '--dbname='.$database, $root.'/database.dump'], $root, $processEnv);
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/app-backup', FilesystemIterator::SKIP_DOTS)) as $file) {
        $destination = $installed.'/storage/app/'.substr($file->getPathname(), strlen($root.'/app-backup') + 1);
        if (! is_dir(dirname($destination))) {
            mkdir(dirname($destination), 0700, true);
        }
        if (! copy($file->getPathname(), $destination)) {
            throw new RuntimeException('Cannot restore private installation state.');
        }
    }
    if (privateSnapshot($installed) !== $snapshot) {
        throw new RuntimeException('Restored installation key/state differs from the backup.');
    }
    foreach ($pdo->query('SELECT storage_key, sha256, storage_disk FROM asset_files')->fetchAll(PDO::FETCH_ASSOC) as $file) {
        if ($file['storage_disk'] !== 's3'
            || hash('sha256', (string) $client->getObject(['Bucket' => $bucket, 'Key' => $file['storage_key']])['Body']) !== $file['sha256']) {
            throw new RuntimeException('Restored database does not resolve its original S3 object.');
        }
    }
    releaseArtisan($installed, $environment, ['installation:ready']);
    echo "Checking restored account, installer lock and private preview through HTTP.\n";
    $server = startReleaseServer($installed, $environment);
    (new Process([PHP_BINARY, __DIR__.'/http-onboarding.php'], $installed,
        array_replace($environment, ['SMOKE_RESTORE' => '1']), timeout: 90))->mustRun();
    echo "S3 recovery {$version}: source services stopped; empty PostgreSQL/S3 target; original/derivative SHA-256, preserved key/account, installer lock, private HTTP preview, tamper and nonempty-target rejection passed.\n";
} finally {
    $server?->stop();
    $s3?->stop();
    if ($postgres?->isRunning() && $pgData !== null) {
        recoveryCommand([$pgBin.'/pg_ctl'.$suffix, '-D', $pgData, '-m', 'fast', '-w', 'stop'], $root);
        $postgres->stop();
    }
    removeReleaseFixture($root);
}
