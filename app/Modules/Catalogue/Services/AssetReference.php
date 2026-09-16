<?php

declare(strict_types=1);

namespace App\Modules\Catalogue\Services;

use App\Modules\Catalogue\Models\Asset;
use Illuminate\Support\Str;

final class AssetReference
{
    public static function resolve(?string $assetId, ?string $accessionNumber): ?Asset
    {
        $id = trim($assetId ?? '');
        if ($id !== '') {
            return self::byId($id);
        }

        $reference = trim($accessionNumber ?? '');
        if ($reference === '') {
            return null;
        }

        // Preserve exact accession-number precedence, even for ULID-shaped accessions.
        return Asset::query()->where('accession_number', $reference)->first()
            ?? self::byId($reference);
    }

    private static function byId(string $id): ?Asset
    {
        $exact = Asset::query()->whereKey($id)->first();
        if ($exact !== null || ! Str::isUlid(strtoupper($id))) {
            return $exact;
        }

        // Imported IDs may be uppercase; Laravel's generated ULIDs are lowercase.
        return Asset::query()->whereIn('id', [strtolower($id), strtoupper($id)])->first();
    }
}
