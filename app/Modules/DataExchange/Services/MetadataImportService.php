<?php

declare(strict_types=1);

namespace App\Modules\DataExchange\Services;

use App\Models\User;
use App\Modules\Catalogue\Models\Asset;
use App\Modules\Catalogue\Models\AssetRight;
use App\Modules\Catalogue\Models\Tag;
use App\Modules\DataExchange\Jobs\RunMetadataImport;
use App\Modules\DataExchange\Models\MetadataImport;
use App\Modules\DataExchange\Models\MetadataImportRow;
use App\Modules\DataExchange\Support\CsvReader;
use App\Modules\Ingest\Models\AssetAuditEvent;
use DateTimeInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * Accepts, previews and applies bounded CSV metadata imports.
 *
 * Nothing in an uploaded file reaches the catalogue before a human confirms the
 * dry run: the preview records every intended change, and the confirmed run
 * re-checks ownership and the optimistic lock version row by row.
 */
class MetadataImportService
{
    private const TEXT_MIME_TYPES = ['text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel'];

    public function __construct(
        private readonly CsvReader $reader,
        private readonly MetadataColumnMapper $mapper,
        private readonly MetadataRowValidator $validator,
    ) {}

    public function accept(UploadedFile $file, User $user, string $writeMode): MetadataImport
    {
        $this->validateUpload($file, $writeMode);
        $diskName = (string) config('filesystems.default');
        $disk = Storage::disk($diskName);
        $storageKey = 'exchange/imports/'.str()->ulid().'/'.str()->random(32).'.csv';
        $path = (string) $file->getRealPath();
        $stream = fopen($path, 'rb');
        if ($stream === false) {
            throw new RuntimeException('Import stream unavailable.');
        }
        try {
            if (! $disk->writeStream($storageKey, $stream, ['visibility' => 'private'])) {
                throw new RuntimeException('Import write failed.');
            }
            $import = MetadataImport::query()->create([
                'created_by_user_id' => $user->id,
                'storage_disk' => $diskName,
                'storage_key' => $storageKey,
                'original_filename' => mb_substr(basename($file->getClientOriginalName()), 0, 255),
                'byte_size' => (int) $file->getSize(),
                'content_sha256' => (string) hash_file('sha256', $path),
                'write_mode' => $writeMode,
                'status' => 'analysing',
            ]);
        } catch (Throwable $exception) {
            $disk->delete($storageKey);
            throw $exception;
        } finally {
            fclose($stream);
        }

        if ($import->byte_size > (int) config('exchange.sync_analysis_bytes')) {
            // Heavy files never block the request; the worker analyses them.
            Queue::connection('ingest')->push(new RunMetadataImport($import->id, 'analyse'));

            return $import;
        }
        $this->analyse($import);

        return $import->refresh();
    }

    public function reanalyse(MetadataImport $import, string $writeMode): void
    {
        $this->assertWriteMode($writeMode);
        if ($import->isBusy()) {
            throw ValidationException::withMessages(['file' => 'Deze import wordt op dit moment verwerkt.']);
        }
        if ($import->status === 'completed') {
            throw ValidationException::withMessages(['file' => 'Een afgeronde import kan niet opnieuw worden voorbereid. Upload een nieuw bestand.']);
        }
        $import->update(['write_mode' => $writeMode, 'status' => 'analysing', 'failure_reason' => null, 'claim_token' => null]);
        if ($import->byte_size > (int) config('exchange.sync_analysis_bytes')) {
            Queue::connection('ingest')->push(new RunMetadataImport($import->id, 'analyse'));

            return;
        }
        $this->analyse($import);
    }

    /**
     * @return bool false when another worker still holds a live claim, so the
     *              job must come back instead of reporting the run as done
     */
    public function analyse(MetadataImport $import): bool
    {
        $token = $this->claim($import, ['received', 'analysing'], 'analysing');
        if ($token === null) {
            return ! $this->claimIsLive($import, ['analysing', 'running']);
        }
        try {
            $parsed = $this->read($import);
            $mapping = $this->mapper->mapping($parsed['header']);
            $fields = $this->mapper->mappedFields($mapping);
            foreach (['accession_number', 'lock_version'] as $required) {
                if (! in_array($required, $fields, true)) {
                    throw ValidationException::withMessages(['file' => 'De kolom '.$required.' ontbreekt. Zonder archiefnummer en versie kan bestaande data niet veilig worden bijgewerkt.']);
                }
            }
            if (array_intersect($fields, MetadataColumnMapper::WRITABLE) === []) {
                throw ValidationException::withMessages(['file' => 'Geen enkele herkenbare metadatakolom gevonden. Er valt niets bij te werken.']);
            }
            $user = $import->creator;
            if (! $user instanceof User || ! $user->hasPermission('assets.update')) {
                throw ValidationException::withMessages(['file' => 'De indiener heeft geen rechten meer om metadata bij te werken.']);
            }
            $rows = $this->buildRows($import, $mapping, $parsed['rows'], $user);
            DB::transaction(function () use ($import, $mapping, $parsed, $rows, $token): void {
                $import->rows()->delete();
                foreach (array_chunk($rows, 200) as $chunk) {
                    MetadataImportRow::query()->insert($chunk);
                }
                $this->release($import, $token, [
                    'status' => 'analysed',
                    'delimiter' => $parsed['delimiter'],
                    'column_mapping' => $mapping,
                    'row_count' => count($rows),
                    'summary' => $this->summary($import),
                    'analysed_at' => now(),
                ]);
            });
        } catch (ValidationException $exception) {
            $this->release($import, $token, ['status' => 'failed', 'failure_reason' => implode(' ', array_merge(...array_values($exception->errors())))]);
        } catch (Throwable $exception) {
            Log::error('Metadata import analysis failed.', ['import_id' => $import->id, 'exception_type' => $exception::class]);
            $this->release($import, $token, ['status' => 'failed', 'failure_reason' => 'Analyse mislukt. Controleer opslag en database en probeer opnieuw.']);
        }

        return true;
    }

    public function confirm(MetadataImport $import, User $user, string $checksum): void
    {
        if ($import->created_by_user_id !== $user->id) {
            throw ValidationException::withMessages(['file' => 'Alleen de indiener kan deze import bevestigen.']);
        }
        if (! hash_equals($import->content_sha256, $checksum)) {
            throw ValidationException::withMessages(['file' => 'Het voorbeeld hoort niet bij dit bestand. Bekijk de controle opnieuw.']);
        }
        if (! in_array($import->status, ['analysed', 'failed'], true)) {
            throw ValidationException::withMessages(['file' => 'Bevestig pas nadat de controle zonder verwerking is afgerond.']);
        }
        if ((int) ($import->summary['ready'] ?? 0) < 1) {
            throw ValidationException::withMessages(['file' => 'Er zijn geen rijen die veilig kunnen worden bijgewerkt.']);
        }
        DB::transaction(function () use ($import): void {
            $import->update(['status' => 'queued', 'confirmed_at' => now(), 'failure_reason' => null, 'claim_token' => null]);
            // The queue row commits with the import row on the same database.
            Queue::connection('ingest')->push(new RunMetadataImport($import->id, 'apply'));
        });
    }

    /**
     * @return bool false when another worker still holds a live claim, so the
     *              job must come back instead of reporting the run as done
     */
    public function apply(MetadataImport $import): bool
    {
        // "running" is included so a job redelivered after a worker was killed
        // can take the run over; rows already applied are never re-selected and
        // the version check inside each row transaction makes a repeat safe.
        $token = $this->claim($import, ['queued', 'running'], 'running');
        if ($token === null) {
            return ! $this->claimIsLive($import, ['running']);
        }
        $user = $import->creator;
        if (! $user instanceof User || ! $user->hasPermission('assets.update')) {
            $this->release($import, $token, ['status' => 'failed', 'failure_reason' => 'De indiener heeft geen rechten meer om metadata bij te werken.']);

            return true;
        }
        $rows = $import->rows()->where('status', 'ready')->orderBy('row_number')->get();
        foreach ($rows as $row) {
            try {
                $this->applyRow($import, $row, $user);
            } catch (Throwable $exception) {
                Log::error('Metadata import row failed.', ['import_id' => $import->id, 'row' => $row->row_number, 'exception_type' => $exception::class]);
                $row->update(['status' => 'failed', 'messages' => ['Bijwerken mislukt. Controleer database en opslag en probeer opnieuw.']]);
            }
        }
        $this->release($import, $token, ['status' => 'completed', 'completed_at' => now(), 'summary' => $this->summary($import)]);

        return true;
    }

    public function markFailed(MetadataImport $import, string $reason): void
    {
        if ($import->isBusy()) {
            $import->update(['status' => 'failed', 'failure_reason' => $reason, 'claim_token' => null, 'summary' => $this->summary($import)]);
        }
    }

    /**
     * Reports imports whose worker never came back as failed, so a run cannot
     * sit in "bezig" for ever once no job is left to redeliver it.
     *
     * @return int the number of imports released from a dead claim
     */
    public function recoverStalled(): int
    {
        $cutoff = now()->subSeconds((int) config('exchange.abandoned_claim_seconds'));
        $recovered = 0;
        foreach (MetadataImport::query()->whereIn('status', ['analysing', 'running'])->where('started_at', '<', $cutoff)->cursor() as $import) {
            $released = MetadataImport::query()->whereKey($import->id)->whereIn('status', ['analysing', 'running'])->where('started_at', '<', $cutoff)
                ->update([
                    'status' => 'failed',
                    'failure_reason' => 'De verwerking is afgebroken: de worker is gestopt voordat de import klaar was. Bevestig opnieuw om verder te gaan.',
                    'claim_token' => null,
                ]);
            $recovered += $released;
        }

        return $recovered;
    }

    /**
     * A photo in the trash is recoverable, so saying it no longer exists sends
     * the archivist looking for a problem that is not there. The distinction is
     * for the message only: a trashed photo is never written to either way.
     */
    private function missingAssetMessage(bool $trashed): string
    {
        return $trashed
            ? 'Deze foto staat in de prullenbak en is niet bijgewerkt. Zet de foto terug en bevestig de import opnieuw.'
            : 'De foto bestaat niet meer.';
    }

    private function applyRow(MetadataImport $import, MetadataImportRow $row, User $user): void
    {
        DB::transaction(function () use ($import, $row, $user): void {
            $asset = Asset::query()->whereKey($row->asset_id)->lockForUpdate()->first();
            if (! $asset instanceof Asset) {
                $row->update(['status' => 'failed', 'messages' => [$this->missingAssetMessage(
                    Asset::onlyTrashed()->whereKey($row->asset_id)->exists()
                )]]);

                return;
            }
            if (! Gate::forUser($user)->allows('update', $asset)) {
                $row->update(['status' => 'failed', 'messages' => ['Je mag deze foto niet meer bijwerken.']]);

                return;
            }
            if ($asset->lock_version !== $row->expected_lock_version) {
                $row->update(['status' => 'failed', 'messages' => ['Deze foto is inmiddels gewijzigd (versie '.$asset->lock_version.'). Exporteer opnieuw en controleer de wijziging.']]);

                return;
            }
            $asset->load('tags', 'rights');
            try {
                $plan = $this->plan($asset, $row->mappedValues(), $import->write_mode);
            } catch (ValidationException $exception) {
                $row->update(['status' => 'failed', 'messages' => array_merge(...array_values($exception->errors()))]);

                return;
            }
            if ($plan === []) {
                $row->update(['status' => 'unchanged', 'changes' => [], 'messages' => ['Geen wijziging nodig.']]);

                return;
            }
            $before = $this->snapshot($asset);
            foreach (['title', 'description', 'date_display', 'date_precision', 'date_earliest', 'date_latest'] as $field) {
                if (array_key_exists($field, $plan)) {
                    $asset->{$field} = $plan[$field]['after'];
                }
            }
            $asset->lock_version++;
            $asset->save();
            if (array_key_exists('tags', $plan)) {
                $tagIds = [];
                foreach ($this->stringList($plan['tags']['after']) as $name) {
                    $tagIds[] = Tag::query()->firstOrCreate(['name' => $name], ['slug' => hash('sha256', $name)])->id;
                }
                $asset->tags()->sync($tagIds);
            }
            if (array_key_exists('rights', $plan)) {
                $right = $asset->rights()->latest('id')->first();
                $right === null ? $asset->rights()->create($plan['rights']['after']) : $right->update($plan['rights']['after']);
            }
            AssetAuditEvent::query()->create([
                'asset_id' => $asset->id,
                'actor_user_id' => $user->id,
                'event_type' => 'metadata.imported',
                'details' => [
                    'import_id' => $import->id,
                    'row_number' => $row->row_number,
                    'write_mode' => $import->write_mode,
                    'revision' => $asset->lock_version,
                    'before' => $before,
                    'after' => $this->snapshot($asset->fresh(['tags', 'rights']) ?? $asset),
                ],
            ]);
            $row->update(['status' => 'applied', 'changes' => $plan, 'messages' => []]);
        });
    }

    /**
     * @param  list<array{column: string, field: string|null, status: string}>  $mapping
     * @param  array<int, list<string>>  $rows
     * @return list<array<string, mixed>>
     */
    private function buildRows(MetadataImport $import, array $mapping, array $rows, User $user): array
    {
        $prepared = [];
        $seenAccessions = [];
        $now = now();
        $assets = $this->assetsFor($mapping, $rows);
        $number = 0;
        foreach ($rows as $cells) {
            $number++;
            $values = $this->mapper->values($mapping, $cells);
            $accession = $values['accession_number'] ?? null;
            unset($values['accession_number']);
            $lockVersion = $values['lock_version'] ?? null;
            unset($values['lock_version']);
            $messages = [];
            $asset = $accession === null ? null : ($assets[$accession] ?? null);
            if ($accession === null) {
                $messages[] = 'Kolom accession_number is leeg.';
            } elseif (isset($seenAccessions[$accession])) {
                $messages[] = 'Archiefnummer '.$accession.' komt meerdere keren voor in dit bestand.';
            } elseif ($asset === null) {
                $messages[] = Asset::onlyTrashed()->where('accession_number', $accession)->exists()
                    ? 'Foto '.$accession.' staat in de prullenbak en wordt niet bijgewerkt. Zet de foto terug en controleer daarna opnieuw.'
                    : 'Geen foto gevonden met archiefnummer '.$accession.'. Importeren maakt nooit nieuwe foto’s aan.';
            } elseif (! Gate::forUser($user)->allows('update', $asset)) {
                $messages[] = 'Je mag deze foto niet bijwerken.';
                $asset = null;
            }
            if ($accession !== null) {
                $seenAccessions[$accession] = true;
            }
            if ($lockVersion === null || preg_match('/^\d{1,9}$/', $lockVersion) !== 1) {
                $messages[] = 'Kolom lock_version moet het versienummer uit de export bevatten.';
            } elseif ($asset !== null && (int) $lockVersion !== $asset->lock_version) {
                $messages[] = 'Versie '.$lockVersion.' komt niet overeen met de huidige versie '.$asset->lock_version.'. Exporteer opnieuw.';
                $messages[] = 'Bestaande gegevens blijven ongewijzigd.';
            }
            $validated = $this->validator->validate($values);
            $messages = array_merge($messages, $validated['errors']);
            $changes = [];
            if ($messages === [] && $asset !== null) {
                try {
                    $changes = $this->plan($asset, $validated['values'], $import->write_mode);
                } catch (ValidationException $exception) {
                    $messages = array_merge($messages, array_merge(...array_values($exception->errors())));
                }
            }
            $status = match (true) {
                $messages !== [] => 'error',
                $changes === [] => 'unchanged',
                default => 'ready',
            };
            if ($status === 'unchanged') {
                $messages[] = 'Geen wijziging nodig.';
            }
            $prepared[] = [
                'id' => (string) str()->ulid(),
                'metadata_import_id' => $import->id,
                'row_number' => $number,
                'accession_number' => $accession === null ? null : mb_substr($accession, 0, 255),
                'asset_id' => $status === 'error' ? null : $asset?->id,
                'expected_lock_version' => $lockVersion === null || preg_match('/^\d{1,9}$/', $lockVersion) !== 1 ? null : (int) $lockVersion,
                'status' => $status,
                'mapped_values' => json_encode($validated['values'], JSON_THROW_ON_ERROR),
                'changes' => json_encode($changes, JSON_THROW_ON_ERROR),
                'messages' => json_encode($messages, JSON_THROW_ON_ERROR),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        return $prepared;
    }

    /**
     * @param  list<array{column: string, field: string|null, status: string}>  $mapping
     * @param  array<int, list<string>>  $rows
     * @return array<string, Asset>
     */
    private function assetsFor(array $mapping, array $rows): array
    {
        $accessions = [];
        foreach ($rows as $cells) {
            $accession = $this->mapper->values($mapping, $cells)['accession_number'] ?? null;
            if ($accession !== null) {
                $accessions[$accession] = true;
            }
        }
        $assets = [];
        foreach (array_chunk(array_keys($accessions), 500) as $chunk) {
            foreach (Asset::query()->with(['tags', 'rights'])->whereIn('accession_number', $chunk)->get() as $asset) {
                $assets[$asset->accession_number] = $asset;
            }
        }

        return $assets;
    }

    /**
     * Describes exactly what a row would change, honouring the protective write mode.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, array{before: mixed, after: mixed}>
     */
    private function plan(Asset $asset, array $values, string $mode): array
    {
        $fillEmptyOnly = $mode === 'fill_empty';
        $changes = [];
        foreach (['title', 'description', 'date_display'] as $field) {
            if (! array_key_exists($field, $values)) {
                continue;
            }
            $before = $asset->{$field};
            if (($fillEmptyOnly && $before !== null && $before !== '') || (string) $before === (string) $values[$field]) {
                continue;
            }
            $changes[$field] = ['before' => $before, 'after' => $values[$field]];
        }
        if (array_key_exists('date_precision', $values)) {
            $before = ['date_precision' => $asset->date_precision, 'date_earliest' => $this->dateString($asset, 'date_earliest'), 'date_latest' => $this->dateString($asset, 'date_latest')];
            $after = ['date_precision' => $values['date_precision'], 'date_earliest' => $values['date_earliest'] ?? null, 'date_latest' => $values['date_latest'] ?? null];
            $dated = $asset->date_precision !== 'unknown' || $before['date_earliest'] !== null;
            if (! ($fillEmptyOnly && $dated) && $before !== $after) {
                foreach ($after as $field => $value) {
                    $changes[$field] = ['before' => $before[$field], 'after' => $value];
                }
            }
        }
        if (array_key_exists('tags', $values)) {
            $current = [];
            foreach ($asset->tags as $tag) {
                $current[] = (string) $tag->name;
            }
            sort($current);
            $wanted = $this->stringList($values['tags']);
            sort($wanted);
            $after = $wanted;
            if ($fillEmptyOnly) {
                $after = array_values(array_unique(array_merge($current, $wanted)));
                sort($after);
            }
            if (count($after) > 20) {
                throw ValidationException::withMessages(['tags' => 'Samen met de bestaande trefwoorden zouden er meer dan 20 trefwoorden ontstaan.']);
            }
            if ($after !== $current) {
                $changes['tags'] = ['before' => $current, 'after' => $after];
            }
        }
        $rights = array_filter([
            'rights_holder' => $values['rights_holder'] ?? null,
            'verification_status' => $values['rights_status'] ?? null,
            'note' => $values['rights_note'] ?? null,
        ], fn ($value): bool => $value !== null);
        if ($rights !== []) {
            $right = $asset->rights->sortBy('id')->last();
            $before = $right instanceof AssetRight
                ? ['rights_holder' => $right->rights_holder, 'verification_status' => $right->verification_status, 'note' => $right->note]
                : ['rights_holder' => null, 'verification_status' => 'unverified', 'note' => null];
            $after = $before;
            foreach ($rights as $field => $value) {
                $existing = $before[$field];
                $occupied = $existing !== null && $existing !== '' && ! ($field === 'verification_status' && $existing === 'unverified');
                if ($fillEmptyOnly && $occupied) {
                    continue;
                }
                $after[$field] = $value;
            }
            if ($after !== $before) {
                $changes['rights'] = ['before' => $before, 'after' => $after];
            }
        }

        return $changes;
    }

    private function dateString(Asset $asset, string $field): ?string
    {
        $value = $asset->getAttribute($field);
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        return is_string($value) && $value !== '' ? substr($value, 0, 10) : null;
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $values): array
    {
        $strings = [];
        foreach (is_array($values) ? $values : [] as $value) {
            if (is_string($value) || is_int($value)) {
                $strings[] = (string) $value;
            }
        }

        return $strings;
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(Asset $asset): array
    {
        $right = $asset->rights()->latest('id')->first();

        return [
            'metadata' => [
                'title' => $asset->title,
                'description' => $asset->description,
                'date_precision' => $asset->date_precision,
                'date_earliest' => $this->dateString($asset, 'date_earliest'),
                'date_latest' => $this->dateString($asset, 'date_latest'),
                'date_display' => $asset->date_display,
            ],
            'tags' => $asset->tags()->pluck('name')->sort()->values()->all(),
            'rights' => $right?->only(['rights_holder', 'verification_status', 'note']),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function summary(MetadataImport $import): array
    {
        $counts = $import->rows()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');
        $summary = [];
        foreach (['ready', 'unchanged', 'error', 'applied', 'failed'] as $status) {
            $summary[$status] = (int) ($counts[$status] ?? 0);
        }
        $summary['total'] = (int) $import->rows()->count();

        return $summary;
    }

    /**
     * @return array{delimiter: string, header: list<string>, rows: array<int, list<string>>}
     */
    private function read(MetadataImport $import): array
    {
        $disk = Storage::disk($import->storage_disk);
        $stream = $disk->readStream($import->storage_key);
        if (! is_resource($stream)) {
            throw new RuntimeException('Import file unreadable.');
        }
        try {
            return $this->reader->parse($stream);
        } finally {
            fclose($stream);
        }
    }

    /**
     * @param  list<string>  $from
     */
    private function claim(MetadataImport $import, array $from, string $to): ?string
    {
        $token = (string) str()->uuid();
        $claimed = MetadataImport::query()->whereKey($import->id)->whereIn('status', $from)
            ->where(fn ($query) => $query->whereNull('claim_token')->orWhere('started_at', '<', now()->subSeconds((int) config('exchange.stale_claim_seconds'))))
            ->update(['status' => $to, 'started_at' => now(), 'claim_token' => $token, 'attempts' => DB::raw('attempts + 1')]);
        if ($claimed !== 1) {
            return null;
        }
        $import->refresh();

        return $token;
    }

    /**
     * True when the row is still held by a claim young enough to belong to a
     * worker that may yet finish it. The caller must then wait rather than
     * conclude there is nothing left to do.
     *
     * @param  list<string>  $busyStatuses
     */
    private function claimIsLive(MetadataImport $import, array $busyStatuses): bool
    {
        $fresh = MetadataImport::query()->whereKey($import->id)->first();
        if (! $fresh instanceof MetadataImport || ! in_array($fresh->status, $busyStatuses, true)) {
            return false;
        }
        $started = $fresh->timestamp('started_at');

        return $started === null || $started->getTimestamp() >= now()->subSeconds((int) config('exchange.stale_claim_seconds'))->getTimestamp();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function release(MetadataImport $import, string $token, array $attributes): void
    {
        MetadataImport::query()->whereKey($import->id)->where('claim_token', $token)
            ->update(array_merge($attributes, ['claim_token' => null]));
        $import->refresh();
    }

    private function validateUpload(UploadedFile $file, string $writeMode): void
    {
        $this->assertWriteMode($writeMode);
        $maxBytes = (int) config('exchange.max_import_bytes');
        $size = $file->getSize();
        if (! $file->isValid() || $size === false || $size <= 0 || $size > $maxBytes) {
            throw ValidationException::withMessages(['file' => 'Het CSV-bestand moet geldig zijn en tussen 1 en '.$maxBytes.' bytes groot zijn.']);
        }
        if (! in_array(mb_strtolower($file->getClientOriginalExtension()), ['csv', 'txt'], true)) {
            throw ValidationException::withMessages(['file' => 'Gebruik een CSV-bestand (.csv).']);
        }
        if (! in_array((string) $file->getMimeType(), self::TEXT_MIME_TYPES, true)) {
            throw ValidationException::withMessages(['file' => 'Alleen platte CSV-tekst wordt gelezen. Sla je spreadsheet op als CSV UTF-8.']);
        }
    }

    private function assertWriteMode(string $writeMode): void
    {
        if (! in_array($writeMode, ['fill_empty', 'overwrite'], true)) {
            throw ValidationException::withMessages(['write_mode' => 'Kies hoe bestaande gegevens worden behandeld.']);
        }
    }
}
