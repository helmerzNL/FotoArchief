<?php

declare(strict_types=1);

namespace App\Modules\Ingest\Services;

use App\Modules\Ingest\Exceptions\UnreadablePdf;
use RuntimeException;

/**
 * Renders the first page of a PDF to a JPEG so the normal derivative pipeline can
 * produce previews. PDFs are untrusted input: poppler runs without a shell, with a
 * wall-clock limit and a bounded output size, and only page 1 is ever rendered.
 *
 * A missing binary is an installation problem (RuntimeException, retryable once the
 * image is fixed); a bad PDF is permanent (UnreadablePdf).
 */
class PdfPageRenderer
{
    private const OUTPUT_LIMIT = 1_048_576;

    private const RENDER_LONG_SIDE = 2000;

    public function __construct(
        private readonly ?string $pdfinfo = null,
        private readonly ?string $pdftoppm = null,
        private readonly ?int $timeoutSeconds = null,
    ) {}

    public function isPdf(string $path): bool
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return false;
        }
        try {
            // The PDF header may be preceded by a few bytes of junk, but not by much.
            return str_contains((string) fread($handle, 1024), '%PDF-');
        } finally {
            fclose($handle);
        }
    }

    /** @return array{path: string, pages: int} path is a temporary JPEG the caller must delete */
    public function renderFirstPage(string $pdfPath): array
    {
        $info = $this->run([$this->pdfinfoBinary(), '-f', '1', '-l', '1', $pdfPath]);
        if ($info['exit'] !== 0) {
            throw new UnreadablePdf(__('uploads.pdf.unreadable'));
        }
        if (preg_match('/^Encrypted:\s+yes/mi', $info['stdout']) === 1) {
            throw new UnreadablePdf(__('uploads.pdf.encrypted'));
        }
        if (preg_match('/^Pages:\s+(\d+)/mi', $info['stdout'], $match) !== 1 || (int) $match[1] < 1) {
            throw new UnreadablePdf(__('uploads.pdf.no_pages'));
        }
        $pages = (int) $match[1];

        $prefix = tempnam(sys_get_temp_dir(), 'fotoarchief-pdf-');
        if ($prefix === false) {
            throw new RuntimeException(__('uploads.storage_error'));
        }
        $output = $prefix.'.jpg';
        try {
            $render = $this->run([
                $this->pdftoppmBinary(), '-jpeg', '-jpegopt', 'quality=90', '-f', '1', '-l', '1',
                '-scale-to', (string) self::RENDER_LONG_SIDE, '-singlefile', $pdfPath, $prefix,
            ]);
            if ($render['exit'] !== 0 || ! is_file($output) || filesize($output) === 0) {
                throw new UnreadablePdf(__('uploads.pdf.unreadable'));
            }
        } catch (\Throwable $exception) {
            if (is_file($output)) {
                unlink($output);
            }
            throw $exception;
        } finally {
            if (is_file($prefix)) {
                unlink($prefix);
            }
        }

        return ['path' => $output, 'pages' => $pages];
    }

    private function pdfinfoBinary(): string
    {
        return $this->pdfinfo ?? (string) config('ingest.pdf.info_binary', 'pdfinfo');
    }

    private function pdftoppmBinary(): string
    {
        return $this->pdftoppm ?? (string) config('ingest.pdf.render_binary', 'pdftoppm');
    }

    /**
     * @param  list<string>  $command
     * @return array{exit: int, stdout: string}
     */
    private function run(array $command): array
    {
        $timeout = $this->timeoutSeconds ?? (int) config('ingest.pdf.timeout_seconds', 60);
        $process = @proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (! is_resource($process)) {
            throw new RuntimeException(__('uploads.pdf.unavailable'));
        }
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $stdout = '';
        $deadline = microtime(true) + $timeout;
        $exit = null;
        while (true) {
            $read = [$pipes[1], $pipes[2]];
            $write = $except = null;
            if (stream_select($read, $write, $except, 0, 200_000) > 0) {
                foreach ($read as $stream) {
                    $chunk = (string) fread($stream, 65536);
                    if ($stream === $pipes[1] && strlen($stdout) < self::OUTPUT_LIMIT) {
                        $stdout .= $chunk;
                    }
                }
            }
            $status = proc_get_status($process);
            if (! $status['running']) {
                $exit = $status['exitcode'];
                break;
            }
            if (microtime(true) > $deadline) {
                proc_terminate($process, 9);
                break;
            }
        }
        $stdout .= (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $closed = proc_close($process);
        if ($exit === null) {
            throw new UnreadablePdf(__('uploads.pdf.timeout'));
        }
        // 127 is "command not found" from the exec step: the tool is not installed.
        if ($exit === 127 || ($exit === -1 && $closed === 127)) {
            throw new RuntimeException(__('uploads.pdf.unavailable'));
        }

        return ['exit' => $exit, 'stdout' => substr($stdout, 0, self::OUTPUT_LIMIT)];
    }
}
