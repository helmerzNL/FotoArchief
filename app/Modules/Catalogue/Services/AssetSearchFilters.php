<?php

declare(strict_types=1);

namespace App\Modules\Catalogue\Services;

use App\Modules\Catalogue\Models\Asset;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;

class AssetSearchFilters
{
    /** @param Builder<Asset> $query */
    public static function missingDating(Builder $query): void
    {
        $query->where('date_precision', 'unknown')
            ->orWhere(fn ($q) => $q->where('date_precision', 'before')->whereNull('date_latest'))
            ->orWhere(fn ($q) => $q->where('date_precision', '!=', 'before')->whereNull('date_earliest'));
    }

    /** @return array<string, array<int, mixed>> */
    public static function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:200'],
            'cursor' => ['nullable', 'ulid'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d'],
            'date_precision' => ['nullable', 'string', 'max:30'],
            'person_id' => ['nullable', 'string'],
            'location_id' => ['nullable', 'string'],
            'collection_id' => ['nullable', 'string'],
            'tag_id' => ['nullable', 'string'],
            'rights_status' => ['nullable', 'string', 'max:30'],
            'catalogue_status' => ['nullable', 'string', 'max:30'],
            'missing' => ['nullable', Rule::in(['description', 'dating', 'collection', 'rights'])],
        ];
    }
}
