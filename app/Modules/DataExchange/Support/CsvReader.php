<?php

declare(strict_types=1);

namespace App\Modules\DataExchange\Support;

use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Bounded CSV reader for untrusted spreadsheet uploads.
 */
class CsvReader
{
    private const DELIMITERS = [',', ';', "\t", '|'];

    /**
     * @param  resource  $stream
     * @return array{delimiter: string, header: list<string>, rows: array<int, list<string>>}
     */
    public function parse($stream): array
    {
        $maxBytes = (int) config('exchange.max_import_bytes');
        $contents = stream_get_contents($stream, $maxBytes + 1);
        if ($contents === false) {
            throw new RuntimeException(__('exchange.generated.t_93dc6fe7615c2bd5'));
        }
        if (strlen($contents) > $maxBytes) {
            $this->fail(__('exchange.generated.t_7934639756ce7ec6').number_format($maxBytes / 1048576, 1).__('exchange.generated.t_43a5315f48a0be58'));
        }
        $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents) ?? $contents;
        if ($contents === '' || trim($contents) === '') {
            $this->fail(__('exchange.generated.t_92460416e85e8d0a'));
        }
        if (! mb_check_encoding($contents, 'UTF-8')) {
            $this->fail(__('exchange.generated.t_4294f3d025472757'));
        }
        if (str_contains($contents, "\0")) {
            $this->fail(__('exchange.generated.t_0c12fe49157b4bf6'));
        }

        $delimiter = $this->detectDelimiter($contents);
        $handle = fopen('php://temp', 'r+b');
        if ($handle === false) {
            throw new RuntimeException(__('exchange.generated.t_3bef17122f05ca39'));
        }

        try {
            fwrite($handle, $contents);
            rewind($handle);
            $maxColumns = (int) config('exchange.max_import_columns');
            $maxRows = (int) config('exchange.max_import_rows');
            $maxCell = (int) config('exchange.max_cell_characters');
            $header = null;
            $rows = [];
            $lineNumber = 0;
            while (($cells = fgetcsv($handle, 0, $delimiter, '"', '')) !== false) {
                $lineNumber++;
                if ($cells === [null]) {
                    continue;
                }
                $cells = array_map(fn ($cell) => $this->clean(is_string($cell) ? $cell : '', $maxCell), $cells);
                if ($header === null) {
                    if (count($cells) > $maxColumns) {
                        $this->fail(__('exchange.generated.t_1a09b992465e17a8').$maxColumns.' kolommen.');
                    }
                    $header = array_values($cells);

                    continue;
                }
                if (implode('', $cells) === '') {
                    continue;
                }
                if (count($rows) >= $maxRows) {
                    $this->fail(__('exchange.generated.t_780004b4d256092c').$maxRows.__('exchange.generated.t_05176b800005e19b'));
                }
                $rows[$lineNumber] = array_values($cells);
            }
            if ($header === null || $header === [] || implode('', $header) === '') {
                $this->fail(__('exchange.generated.t_a6534cc7c2548de1'));
            }

            return ['delimiter' => $delimiter, 'header' => $header, 'rows' => $rows];
        } finally {
            fclose($handle);
        }
    }

    /**
     * Undo the spreadsheet formula guard that exports add, so a round trip is lossless.
     */
    public function unescapeFormulaGuard(string $value): string
    {
        if (str_starts_with($value, "'") && preg_match('/^\'[=+\-@\t\r]/', $value) === 1) {
            return substr($value, 1);
        }

        return $value;
    }

    private function clean(string $value, int $maxCell): string
    {
        $value = trim(str_replace(["\r\n", "\r"], "\n", $value));
        if (mb_strlen($value) > $maxCell) {
            $this->fail(__('exchange.generated.t_96d30dae799b98a5').$maxCell.' tekens.');
        }

        return $this->unescapeFormulaGuard($value);
    }

    private function detectDelimiter(string $contents): string
    {
        $firstLine = strtok($contents, "\n");
        $firstLine = $firstLine === false ? '' : $firstLine;
        $best = ',';
        $bestCount = 0;
        foreach (self::DELIMITERS as $delimiter) {
            $count = substr_count($firstLine, $delimiter);
            if ($count > $bestCount) {
                $best = $delimiter;
                $bestCount = $count;
            }
        }

        return $best;
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['file' => $message]);
    }
}
