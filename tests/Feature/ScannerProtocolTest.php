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
