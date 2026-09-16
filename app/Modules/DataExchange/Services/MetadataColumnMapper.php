<?php

declare(strict_types=1);

namespace App\Modules\DataExchange\Services;

/**
 * Maps untrusted CSV headers onto the metadata fields an import may write.
 */
class MetadataColumnMapper
{
    public const FIELDS = [
        'accession_number',
        'lock_version',
        'title',
        'description',
        'date_precision',
        'date_earliest',
        'date_latest',
        'date_display',
        'tags',
        'rights_holder',
        'rights_status',
        'rights_note',
    ];

    public const WRITABLE = [
        'title',
        'description',
        'date_precision',
        'date_earliest',
        'date_latest',
        'date_display',
        'tags',
        'rights_holder',
        'rights_status',
        'rights_note',
    ];

    private const ALIASES = [
        'archiefnummer' => 'accession_number',
        'inventarisnummer' => 'accession_number',
        'versie' => 'lock_version',
        'revisie' => 'lock_version',
        'titel' => 'title',
        'beschrijving' => 'description',
        'omschrijving' => 'description',
        'dateringstype' => 'date_precision',
        'datumtype' => 'date_precision',
        'datum_vanaf' => 'date_earliest',
        'begindatum' => 'date_earliest',
        'datum_tot' => 'date_latest',
        'einddatum' => 'date_latest',
        'datumweergave' => 'date_display',
        'datum' => 'date_display',
        'trefwoorden' => 'tags',
        'tag' => 'tags',
        'rechthebbende' => 'rights_holder',
        'rechtenstatus' => 'rights_status',
        'rechtennotitie' => 'rights_note',
        'rechtennoot' => 'rights_note',
    ];

    /**
     * @param  list<string>  $header
     * @return list<array{column: string, field: string|null, status: string}>
     */
    public function mapping(array $header): array
    {
        $mapping = [];
        $seen = [];
        foreach ($header as $column) {
            $field = $this->field($column);
            if ($field !== null && isset($seen[$field])) {
                $mapping[] = ['column' => $column, 'field' => null, 'status' => 'duplicate'];

                continue;
            }
            if ($field !== null) {
                $seen[$field] = true;
            }
            $mapping[] = ['column' => $column, 'field' => $field, 'status' => $field === null ? 'ignored' : 'mapped'];
        }

        return $mapping;
    }

    /**
     * @param  list<array{column: string, field: string|null, status: string}>  $mapping
     * @param  list<string>  $cells
     * @return array<string, string>
     */
    public function values(array $mapping, array $cells): array
    {
        $values = [];
        foreach ($mapping as $index => $column) {
            if ($column['field'] === null) {
                continue;
            }
            $value = $cells[$index] ?? '';
            if ($value !== '') {
                $values[$column['field']] = $value;
            }
        }

        return $values;
    }

    /**
     * @param  list<array{column: string, field: string|null, status: string}>  $mapping
     * @return list<string>
     */
    public function mappedFields(array $mapping): array
    {
        return array_values(array_filter(array_map(fn (array $column): ?string => $column['field'], $mapping)));
    }

    private function field(string $column): ?string
    {
        $normalized = str_replace([' ', '-', '.'], '_', mb_strtolower(trim($column)));
        if (in_array($normalized, self::FIELDS, true)) {
            return $normalized;
        }

        return self::ALIASES[$normalized] ?? null;
    }
}
