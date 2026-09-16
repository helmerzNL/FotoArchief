<?php

declare(strict_types=1);

namespace App\Modules\DataExchange\Support;

use RuntimeException;

/**
 * Writes RFC 4180 CSV and neutralises spreadsheet formulas.
 */
class CsvWriter
{
    /**
     * @param  list<string>  $header
     * @param  iterable<int, list<string>>  $rows
     */
    public function toString(array $header, iterable $rows): string
    {
        $handle = fopen('php://temp', 'r+b');
        if ($handle === false) {
            throw new RuntimeException('CSV buffer unavailable.');
        }
        try {
            fputcsv($handle, array_map(fn (string $cell): string => $this->guard($cell), $header), ',', '"', '');
            foreach ($rows as $row) {
                fputcsv($handle, array_map(fn (string $cell): string => $this->guard($cell), $row), ',', '"', '');
            }
            rewind($handle);
            $contents = stream_get_contents($handle);
            if ($contents === false) {
                throw new RuntimeException('CSV buffer unreadable.');
            }

            return $contents;
        } finally {
            fclose($handle);
        }
    }

    /**
     * A leading =, +, -, @, tab or carriage return makes a spreadsheet evaluate
     * the cell. The apostrophe guard keeps the value literal; the import reader
     * removes it again so a round trip is lossless.
     */
    public function guard(string $value): string
    {
        return preg_match('/^\'?[=+\-@\t\r]/', $value) === 1 ? "'".$value : $value;
    }
}
