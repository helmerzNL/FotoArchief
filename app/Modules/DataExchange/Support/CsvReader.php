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
            throw new RuntimeException('CSV stream unreadable.');
        }
        if (strlen($contents) > $maxBytes) {
            $this->fail('Het CSV-bestand is groter dan '.number_format($maxBytes / 1048576, 1).' MiB en wordt niet gelezen.');
        }
        $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents) ?? $contents;
        if ($contents === '' || trim($contents) === '') {
            $this->fail('Het CSV-bestand is leeg.');
        }
        if (! mb_check_encoding($contents, 'UTF-8')) {
            $this->fail('Het CSV-bestand is geen geldige UTF-8. Exporteer opnieuw als UTF-8.');
        }
        if (str_contains($contents, "\0")) {
            $this->fail('Het CSV-bestand bevat binaire tekens en wordt niet verwerkt.');
        }

        $delimiter = $this->detectDelimiter($contents);
        $handle = fopen('php://temp', 'r+b');
        if ($handle === false) {
            throw new RuntimeException('CSV buffer unavailable.');
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
                        $this->fail('Het CSV-bestand heeft meer dan '.$maxColumns.' kolommen.');
                    }
                    $header = array_values($cells);

                    continue;
                }
                if (implode('', $cells) === '') {
                    continue;
                }
                if (count($rows) >= $maxRows) {
                    $this->fail('Het CSV-bestand bevat meer dan '.$maxRows.' rijen. Splits het bestand.');
                }
                $rows[$lineNumber] = array_values($cells);
            }
            if ($header === null || $header === [] || implode('', $header) === '') {
                $this->fail('De eerste regel moet kolomnamen bevatten.');
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
            $this->fail('Een cel bevat meer dan '.$maxCell.' tekens.');
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
