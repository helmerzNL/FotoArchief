<?php

declare(strict_types=1);

namespace App\Modules\DataExchange\Services;

use App\Models\User;
use App\Modules\Catalogue\Models\Asset;
use App\Modules\DataExchange\Jobs\BuildDataExport;
use App\Modules\DataExchange\Models\DataExport;
use App\Modules\Ingest\Models\AssetAuditEvent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Queues, builds, releases and prunes authorised exports.
 *
 * Authorisation is checked three times: when the export is requested, when the
 * worker builds it, and again for every asset when the download is served. An
 * artifact that was authorised yesterday therefore cannot be downloaded today
 * by someone who has since lost access to one of its photos.
 */
class DataExportService
{
    public const TYPES = ['metadata_json', 'metadata_csv', 'package_zip'];

    public function __construct(private readonly ExportArchiver $archiver) {}

    /**
     * @param  list<string>  $assetIds
     */
    public function request(User $user, string $type, string $scope, array $assetIds): DataExport
    {
        if (! in_array($type, self::TYPES, true)) {
            throw ValidationException::withMessages(['export_type' => 'Kies een geldig exportformaat.']);
        }
        if (! $user->hasPermission('exports.create')) {
            throw ValidationException::withMessages(['export_type' => 'Je hebt geen rechten om te exporteren.']);
        }
        $maximum = (int) config('exchange.max_export_assets');
        $assets = $scope === 'all'
            ? $this->visibleQuery($user)->limit($maximum + 1)->get()
            : Asset::query()->whereIn('id', array_slice(array_values(array_unique($assetIds)), 0, $maximum + 1))->get();
        if ($assets->isEmpty()) {
            throw ValidationException::withMessages(['asset_ids' => 'Selecteer minstens één foto die je mag inzien.']);
        }
        if ($assets->count() > $maximum) {
            throw ValidationException::withMessages(['asset_ids' => 'Een export bevat maximaal '.$maximum.' foto’s. Maak een kleinere selectie.']);
        }
        foreach ($assets as $asset) {
            if (! Gate::forUser($user)->allows('view', $asset)) {
                throw ValidationException::withMessages(['asset_ids' => 'Eén of meer geselecteerde foto’s vallen buiten jouw toegang.']);
            }
        }

        return DB::transaction(function () use ($user, $type, $assets): DataExport {
            $export = DataExport::query()->create([
                'created_by_user_id' => $user->id,
                'export_type' => $type,
                'asset_ids' => $assets->pluck('id')->values()->all(),
                'asset_count' => $assets->count(),
                'status' => 'queued',
            ]);
            // The queue row and the export row commit on the same database.
            Queue::connection('ingest')->push(new BuildDataExport($export->id));

            return $export;
        });
    }

    /**
     * @return bool false when another worker still holds a live claim, so the
     *              job must come back instead of reporting the build as done
     */
    public function build(DataExport $export): bool
    {
        $token = $this->claim($export);
        if ($token === null) {
            return ! $this->claimIsLive($export);
        }
        $user = $export->creator;
        if (! $user instanceof User || ! $user->hasPermission('exports.create')) {
            $this->release($export, $token, ['status' => 'failed', 'failure_reason' => 'De aanvrager heeft geen exportrechten meer.']);

            return true;
        }
        try {
            $authorized = [];
            $skipped = [];
            $assets = Asset::query()->with(['files', 'tags', 'rights'])->whereIn('id', $export->assetIds())->get()->keyBy('id');
            foreach ($export->assetIds() as $assetId) {
                $asset = $assets->get($assetId);
                if (! $asset instanceof Asset) {
                    $skipped[] = ['accession_number' => $assetId, 'reason' => 'Foto bestaat niet meer.'];

                    continue;
                }
                // Access is re-checked here, not trusted from the request that queued the export.
                if (! Gate::forUser($user)->allows('view', $asset)) {
                    $skipped[] = ['accession_number' => (string) $asset->accession_number, 'reason' => 'Geen toegang meer tot deze foto.'];

                    continue;
                }
                $authorized[] = $asset;
            }
            if ($authorized === []) {
                $this->release($export, $token, ['status' => 'failed', 'failure_reason' => 'Geen enkele geselecteerde foto valt nog binnen jouw toegang.']);

                return true;
            }
            $result = $this->archiver->build($export, $authorized, $skipped);
            DB::transaction(function () use ($export, $token, $result, $authorized, $user): void {
                $this->release($export, $token, [
                    'status' => 'ready',
                    'storage_disk' => $result['storage_disk'],
                    'storage_key' => $result['storage_key'],
                    'download_filename' => $result['filename'],
                    'byte_size' => $result['byte_size'],
                    'sha256' => $result['sha256'],
                    'manifest' => $result['manifest'],
                    'asset_count' => count($authorized),
                    // Only the assets that survived the build are part of the artifact, so only those are re-checked at download time.
                    'asset_ids' => array_map(static fn (Asset $asset): string => (string) $asset->id, $authorized),
                    'expires_at' => now()->addMinutes((int) config('exchange.export_ttl_minutes')),
                    'failure_reason' => null,
                ]);
                foreach ($authorized as $asset) {
                    AssetAuditEvent::query()->create([
                        'asset_id' => $asset->id,
                        'actor_user_id' => $user->id,
                        'event_type' => 'export.included',
                        'details' => ['export_id' => $export->id, 'export_type' => $export->export_type],
                    ]);
                }
            });
        } catch (Throwable $exception) {
            Log::error('Export build failed.', ['export_id' => $export->id, 'exception_type' => $exception::class]);
            $this->release($export, $token, ['status' => 'failed', 'failure_reason' => 'Samenstellen mislukt. Controleer opslag en worker en probeer opnieuw.']);
        }

        return true;
    }

    public function retry(DataExport $export, User $user): void
    {
        $this->assertOwner($export, $user);
        if ($export->status !== 'failed') {
            throw ValidationException::withMessages(['export_type' => 'Alleen een mislukte export kan opnieuw worden samengesteld.']);
        }
        DB::transaction(function () use ($export): void {
            $export->update(['status' => 'queued', 'failure_reason' => null, 'claim_token' => null]);
            Queue::connection('ingest')->push(new BuildDataExport($export->id));
        });
    }

    /**
     * Issues a short-lived, single-owner download token. The token is stored
     * hashed, so a database copy cannot be replayed as a download link.
     */
    public function issueDownloadToken(DataExport $export, User $user): string
    {
        $this->assertOwner($export, $user);
        $this->assertDownloadable($export);
        $this->assertStillAuthorized($export, $user);
        $token = bin2hex(random_bytes(32));
        $export->update([
            'download_token_hash' => hash('sha256', $token),
            'download_token_expires_at' => now()->addMinutes((int) config('exchange.download_ttl_minutes')),
        ]);

        return $token;
    }

    /**
     * @return array{disk: string, key: string, filename: string}
     */
    public function authorizeDownload(DataExport $export, User $user, string $token): array
    {
        $this->assertOwner($export, $user);
        $this->assertDownloadable($export);
        if (! $export->hasValidDownloadToken($token)) {
            abort(403, 'Deze downloadlink is verlopen. Vraag een nieuwe link aan.');
        }
        $this->assertStillAuthorized($export, $user);
        $export->update(['download_count' => $export->download_count + 1, 'last_downloaded_at' => now()]);
        foreach ($export->assetIds() as $assetId) {
            AssetAuditEvent::query()->create([
                'asset_id' => $assetId,
                'actor_user_id' => $user->id,
                'event_type' => 'export.downloaded',
                'details' => ['export_id' => $export->id, 'export_type' => $export->export_type],
            ]);
        }

        return [
            'disk' => (string) $export->storage_disk,
            'key' => (string) $export->storage_key,
            'filename' => (string) ($export->download_filename ?? 'fotoarchief-export'),
        ];
    }

    /**
     * Deletes artifacts whose retention window has passed.
     */
    public function prune(): int
    {
        $pruned = 0;
        foreach (DataExport::query()->whereIn('status', ['ready', 'revoked'])->where('expires_at', '<=', now())->cursor() as $export) {
            $this->discard($export, 'expired');
            $pruned++;
        }

        return $pruned;
    }

    /**
     * Reports exports whose worker never came back as failed. The reclaim in
     * claim() handles a redelivered job; this handles the case where no job is
     * left to redeliver, so nothing would ever clear the row.
     *
     * @return int the number of exports released from a dead claim
     */
    public function recoverStalled(): int
    {
        $cutoff = now()->subSeconds((int) config('exchange.abandoned_claim_seconds'));
        $recovered = 0;
        foreach (DataExport::query()->where('status', 'running')->where('started_at', '<', $cutoff)->cursor() as $export) {
            $released = DataExport::query()->whereKey($export->id)->where('status', 'running')->where('started_at', '<', $cutoff)
                ->update([
                    'status' => 'failed',
                    'failure_reason' => 'Samenstellen is afgebroken: de worker is gestopt voordat de export klaar was. Probeer het opnieuw.',
                    'claim_token' => null,
                ]);
            $recovered += $released;
        }

        return $recovered;
    }

    public function revoke(DataExport $export): void
    {
        $this->discard($export, 'revoked');
    }

    public function markFailed(DataExport $export, string $reason): void
    {
        if ($export->isBusy()) {
            $export->update(['status' => 'failed', 'failure_reason' => $reason, 'claim_token' => null]);
        }
    }

    /**
     * Every asset is re-checked at release time, so a later permission or
     * publication change cannot be bypassed by an artifact built earlier.
     */
    private function assertStillAuthorized(DataExport $export, User $user): void
    {
        if (! $user->hasPermission('exports.create')) {
            $this->revoke($export);
            abort(403, 'Je hebt geen exportrechten meer. Deze export is ingetrokken.');
        }
        $assets = Asset::query()->whereIn('id', $export->assetIds())->get()->keyBy('id');
        foreach ($export->assetIds() as $assetId) {
            $asset = $assets->get($assetId);
            // A future trash/soft-delete feature must also treat a trashed asset as unavailable here.
            if (! $asset instanceof Asset || ! Gate::forUser($user)->allows('view', $asset)) {
                $this->revoke($export);
                abort(403, 'De toegang tot een van deze foto’s is gewijzigd. Deze export is ingetrokken; maak een nieuwe export.');
            }
        }
    }

    private function discard(DataExport $export, string $status): void
    {
        if ($export->storage_key !== null && $export->storage_disk !== null) {
            Storage::disk($export->storage_disk)->delete($export->storage_key);
        }
        $export->update([
            'status' => $status,
            'storage_key' => null,
            'download_token_hash' => null,
            'download_token_expires_at' => null,
        ]);
    }

    private function assertOwner(DataExport $export, User $user): void
    {
        abort_unless($export->created_by_user_id === $user->id, 404);
    }

    private function assertDownloadable(DataExport $export): void
    {
        if (! $export->isDownloadable()) {
            abort(410, 'Dit exportbestand is niet meer beschikbaar. Maak een nieuwe export.');
        }
    }

    /**
     * @return Builder<Asset>
     */
    private function visibleQuery(User $user): Builder
    {
        $query = Asset::query()->latest('id');
        if (! $user->hasPermission('assets.publish')) {
            $query->where('created_by_user_id', $user->id);
        }

        return $query;
    }

    /**
     * Claims the export for this worker. A row left in "running" by a killed or
     * timed-out worker is reclaimed once the reclaim window has passed: without
     * that, the redelivered job finds a claimed row, does nothing, and the
     * export stays "being built" for ever with no way back.
     */
    private function claim(DataExport $export): ?string
    {
        $token = (string) str()->uuid();
        $stale = now()->subSeconds((int) config('exchange.stale_claim_seconds'));
        $claimed = DataExport::query()->whereKey($export->id)
            ->where(fn ($query) => $query->where('status', 'queued')
                ->orWhere(fn ($running) => $running->where('status', 'running')->where('started_at', '<', $stale)))
            ->update(['status' => 'running', 'started_at' => now(), 'claim_token' => $token, 'attempts' => DB::raw('attempts + 1')]);
        if ($claimed !== 1) {
            return null;
        }
        $export->refresh();

        return $token;
    }

    /**
     * True when the row is still held by a claim young enough to belong to a
     * worker that may yet finish it. The caller must then wait rather than
     * conclude there is nothing left to do.
     */
    private function claimIsLive(DataExport $export): bool
    {
        $fresh = DataExport::query()->whereKey($export->id)->first();
        if (! $fresh instanceof DataExport || $fresh->status !== 'running') {
            return false;
        }
        $started = $fresh->timestamp('started_at');

        return $started === null || $started->getTimestamp() >= now()->subSeconds((int) config('exchange.stale_claim_seconds'))->getTimestamp();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function release(DataExport $export, string $token, array $attributes): void
    {
        DataExport::query()->whereKey($export->id)->where('claim_token', $token)
            ->update(array_merge($attributes, ['claim_token' => null]));
        $export->refresh();
    }
}
