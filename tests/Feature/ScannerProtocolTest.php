<?php

declare(strict_types=1);

use App\Modules\Ingest\Services\MalwareScanner;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;

it('uses framed INSTREAM bytes and accepts only explicit scanner confirmation', function (string $reply, string $expected): void {
    $process = new Process([PHP_BINARY, base_path('tests/Fixtures/clamav-protocol.php'), $reply]);
    $path = tempnam(sys_get_temp_dir(), 'scanner-test-');
    $bytes = str_repeat('protocol test bytes', 10000);
    file_put_contents($path, $bytes);
    try {
        $process->start();
        expect($process->waitUntil(fn (): bool => str_contains($process->getOutput(), "\n")))->toBeTrue();
        $endpoint = trim(explode("\n", $process->getOutput())[0]);
        config(['ingest.scanner' => 'clamav', 'ingest.clamav_host' => '127.0.0.1', 'ingest.clamav_port' => (int) explode(':', $endpoint)[1]]);
        if ($expected === 'clean') {
            expect(app(MalwareScanner::class)->scan($path))->toBe('clean');
        } else {
            expect(fn () => app(MalwareScanner::class)->scan($path))->toThrow($expected);
        }
        $process->wait();
        expect($process->getExitCode())->toBe(0)->and($process->getOutput())->toContain(hash('sha256', $bytes));
    } finally {
        $process->stop();
        unlink($path);
    }
})->with([
    ['stream: OK', 'clean'],
    ['stream: Eicar-Test-Signature FOUND', ValidationException::class],
    ['INSTREAM size limit exceeded. ERROR', RuntimeException::class],
    ['unexpected: OK', RuntimeException::class],
]);

it('fails closed when the configured ClamAV daemon is unavailable', function (): void {
    $listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    expect($listener)->not->toBeFalse($errstr);
    $name = stream_socket_get_name($listener, false);
    expect($name)->toBeString();
    $port = (int) substr($name, strrpos($name, ':') + 1);
    fclose($listener);

    config([
        'ingest.scanner' => 'clamav',
        'ingest.clamav_host' => '127.0.0.1',
        'ingest.clamav_port' => $port,
        'ingest.clamav_timeout' => 1,
    ]);

    $path = tempnam(sys_get_temp_dir(), 'scanner-outage-');
    file_put_contents($path, 'bytes that must not be accepted without ClamAV');

    try {
        expect(fn () => app(MalwareScanner::class)->scan($path))
            ->toThrow(RuntimeException::class, 'Scanner connection unavailable.');
    } finally {
        unlink($path);
    }
});

it('accepts clean bytes and blocks EICAR against a real ClamAV daemon', function (): void {
    $host = getenv('FOTOARCHIEF_TEST_CLAMAV_HOST');
    $port = (int) (getenv('FOTOARCHIEF_TEST_CLAMAV_PORT') ?: 3310);
    if (! is_string($host) || $host === '') {
        $this->markTestSkipped('Set FOTOARCHIEF_TEST_CLAMAV_HOST to run real ClamAV acceptance.');
    }

    config([
        'ingest.scanner' => 'clamav',
        'ingest.clamav_host' => $host,
        'ingest.clamav_port' => $port,
        'ingest.clamav_timeout' => 30,
    ]);

    $clean = tempnam(sys_get_temp_dir(), 'clamav-clean-');
    $eicar = tempnam(sys_get_temp_dir(), 'clamav-eicar-');
    file_put_contents($clean, "FotoArchief ClamAV acceptance\n");
    file_put_contents($eicar, 'X5O!P%@AP[4\PZX54(P^)7CC)7}$EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*');

    try {
        expect(app(MalwareScanner::class)->scan($clean))->toBe('clean');
        expect(fn () => app(MalwareScanner::class)->scan($eicar))->toThrow(ValidationException::class);
    } finally {
        unlink($clean);
        unlink($eicar);
    }
});
