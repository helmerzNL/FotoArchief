<?php

declare(strict_types=1);

namespace App\Modules\Ai\Services;

use App\Models\User;
use App\Modules\Catalogue\Models\Asset;
use App\Modules\Catalogue\Services\AssetReference;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class AiAssetBatchService
{
    public const string INTERNAL_FORMAT = 'internal-v1';

    public function __construct(private readonly AiConfigurationService $configuration) {}

    /**
     * @param  array<array-key, mixed>  $references
     * @return array{asset_ids: list<string>, references: list<array{reference: string, asset_id: string}>}
     */
    public function normalize(array $references, User $user, string $format = 'references'): array
    {
        $limit = (int) $this->configuration->effective()['max_assets_per_batch'];
        if ($references === [] || count($references) > $limit) {
            throw ValidationException::withMessages(['asset_ids' => "Selecteer 1 tot {$limit} assets voor een AI-batch."]);
        }
        if (! in_array($format, ['references', 'legacy', self::INTERNAL_FORMAT], true)) {
            throw ValidationException::withMessages(['asset_ids' => 'Onbekend formaat voor AI-fotoreferenties. Start een nieuwe taak.']);
        }

        $ids = [];
        $resolved = [];
        $errors = [];
        foreach ($references as $reference) {
            if (! is_string($reference) || trim($reference) === '' || strlen($reference) > 255) {
                $errors[] = 'Een fotoreferentie ontbreekt of is ongeldig (maximaal 255 tekens).';

                continue;
            }
            $reference = trim($reference);
            $asset = match ($format) {
                self::INTERNAL_FORMAT => Asset::query()->whereKey($reference)->first(),
                'legacy' => AssetReference::resolve($reference, null) ?? AssetReference::resolve(null, $reference),
                default => AssetReference::resolve(null, $reference),
            };
            if ($asset === null || ! Gate::forUser($user)->allows('update', $asset)) {
                $errors[] = "Foto {$reference} is niet gevonden of niet toegankelijk. Controleer het fotonummer of de interne ID.";

                continue;
            }
            $ids[] = $asset->id;
            $resolved[] = ['reference' => $reference, 'asset_id' => $asset->id];
        }
        if ($errors !== []) {
            throw ValidationException::withMessages(['asset_ids' => $errors]);
        }

        return ['asset_ids' => array_values(array_unique($ids)), 'references' => $resolved];
    }
}
