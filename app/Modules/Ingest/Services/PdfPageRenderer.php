<?php

declare(strict_types=1);

namespace App\Modules\Ingest\Services;

use App\Modules\Ingest\Exceptions\UnreadablePdf;
use App\Modules\Ingest\Support\BoundedProcess;
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
        $result = BoundedProcess::run($command, $this->timeoutSeconds ?? (int) config('ingest.pdf.timeout_seconds', 60));
        if ($result['missing']) {
            throw new RuntimeException(__('uploads.pdf.unavailable'));
        }
        if ($result['timedOut'] || $result['exit'] === null) {
            throw new UnreadablePdf(__('uploads.pdf.timeout'));
        }

        return ['exit' => $result['exit'], 'stdout' => $result['stdout']];
    }
}
