<?php

declare(strict_types=1);

namespace App\Modules\DataExchange\Services;

/**
 * Validates one mapped CSV row against the same metadata rules the photo screen uses.
 */
class MetadataRowValidator
{
    private const PRECISIONS = ['unknown', 'exact', 'circa', 'year', 'range', 'before', 'after', 'decade'];

    private const PRECISION_ALIASES = [
        'onbekend' => 'unknown',
        'exact' => 'exact',
        'circa' => 'circa',
        'jaar' => 'year',
        'bereik' => 'range',
        'voor' => 'before',
        'na' => 'after',
        'decennium' => 'decade',
    ];

    private const RIGHTS_STATUSES = ['unverified', 'verified', 'disputed'];

    private const RIGHTS_ALIASES = [
        'onbevestigd' => 'unverified',
        'ongeverifieerd' => 'unverified',
        'geverifieerd' => 'verified',
        'bevestigd' => 'verified',
        'betwist' => 'disputed',
    ];

    /**
     * @param  array<string, string>  $values
     * @return array{values: array<string, mixed>, errors: list<string>}
     */
    public function validate(array $values): array
    {
        $errors = [];
        $out = [];
        foreach (['title' => 255, 'date_display' => 255, 'rights_holder' => 255, 'description' => 10000, 'rights_note' => 10000] as $field => $max) {
            if (! isset($values[$field])) {
                continue;
            }
            if (mb_strlen($values[$field]) > $max) {
                $errors[] = 'Kolom '.$field.' mag maximaal '.$max.' tekens bevatten.';

                continue;
            }
            $out[$field] = $values[$field];
        }

        if (isset($values['tags'])) {
            $tags = collect(preg_split('/[;,]/', $values['tags']) ?: [])
                ->map(fn (string $tag): string => trim($tag))
                ->filter(fn (string $tag): bool => $tag !== '')
                ->unique()->values();
            if ($tags->count() > 20 || $tags->contains(fn (string $tag): bool => mb_strlen($tag) > 100)) {
                $errors[] = 'Gebruik maximaal 20 trefwoorden van maximaal 100 tekens.';
            } else {
                $out['tags'] = $tags->all();
            }
        }

        if (isset($values['rights_status'])) {
            $status = mb_strtolower($values['rights_status']);
            $status = self::RIGHTS_ALIASES[$status] ?? $status;
            if (! in_array($status, self::RIGHTS_STATUSES, true)) {
                $errors[] = 'Kolom rights_status moet unverified, verified of disputed zijn.';
            } else {
                $out['rights_status'] = $status;
            }
        }

        $errors = array_merge($errors, $this->validateDates($values, $out));

        return ['values' => $out, 'errors' => array_values($errors)];
    }

    /**
     * @param  array<string, string>  $values
     * @param  array<string, mixed>  $out
     * @return list<string>
     */
    private function validateDates(array $values, array &$out): array
    {
        $precision = $values['date_precision'] ?? null;
        $earliest = $values['date_earliest'] ?? null;
        $latest = $values['date_latest'] ?? null;
        if ($precision === null) {
            return $earliest === null && $latest === null
                ? []
                : ['Vul kolom date_precision in zodra date_earliest of date_latest een waarde heeft.'];
        }
        $precision = mb_strtolower($precision);
        $precision = self::PRECISION_ALIASES[$precision] ?? $precision;
        if (! in_array($precision, self::PRECISIONS, true)) {
            return ['Kolom date_precision moet een van: '.implode(', ', self::PRECISIONS).'.'];
        }
        $needsYear = in_array($precision, ['year', 'decade'], true);
        $earliest = $earliest === null ? null : $this->parseDate($earliest, $needsYear);
        $latest = $latest === null ? null : $this->parseDate($latest, $needsYear);
        if ($earliest === false || $latest === false) {
            return ['Gebruik datums in het formaat JJJJ-MM-DD'.($needsYear ? ' of JJJJ' : '').'.'];
        }
        if ($precision === 'unknown') {
            if ($earliest !== null || $latest !== null) {
                return ['Bij date_precision "unknown" moeten de datumkolommen leeg blijven.'];
            }
            $out['date_precision'] = 'unknown';
            $out['date_earliest'] = null;
            $out['date_latest'] = null;

            return [];
        }
        if ($precision === 'before' ? $latest === null : $earliest === null) {
            return ['Deze datering vereist een datum'.($precision === 'before' ? ' in date_latest.' : ' in date_earliest.')];
        }
        if ($precision === 'range' && $latest === null) {
            return ['Een bereik vereist zowel date_earliest als date_latest.'];
        }
        if ($needsYear) {
            $year = (int) substr((string) $earliest, 0, 4);
            if ($precision === 'decade') {
                $year = intdiv($year, 10) * 10;
            }
            if ($year < 1 || $year > ($precision === 'decade' ? 9990 : 9999)) {
                return ['Dit jaar valt buiten het ondersteunde bereik.'];
            }
            $earliest = sprintf('%04d-01-01', $year);
            $latest = sprintf('%04d-12-31', $year + ($precision === 'decade' ? 9 : 0));
        }
        if ($precision === 'exact') {
            if ($latest !== null && $latest !== $earliest) {
                return ['Bij een exacte datum moeten date_earliest en date_latest gelijk zijn.'];
            }
            $latest = $earliest;
        }
        if (($earliest !== null && $latest !== null && $earliest > $latest)
            || ($precision === 'before' && $earliest !== null)
            || ($precision === 'after' && $latest !== null)) {
            return ['Datumbereik is niet geldig voor deze datering.'];
        }
        $out['date_precision'] = $precision;
        $out['date_earliest'] = $earliest;
        $out['date_latest'] = $latest;

        return [];
    }

    private function parseDate(string $value, bool $allowYear): string|false|null
    {
        if ($value === '') {
            return null;
        }
        if ($allowYear && preg_match('/^\d{1,4}$/', $value) === 1) {
            return sprintf('%04d-01-01', (int) $value);
        }
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $matches) !== 1) {
            return false;
        }

        return checkdate((int) $matches[2], (int) $matches[3], (int) $matches[1]) ? $value : false;
    }
}
