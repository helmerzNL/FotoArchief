<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use App\Modules\Catalogue\Models\Asset;
use App\Modules\Catalogue\Models\AssetFile;
use App\Modules\Catalogue\Models\Tag;
use App\Modules\Ingest\Jobs\ProcessUpload;
use App\Modules\Ingest\Models\AssetAuditEvent;
use App\Modules\Ingest\Models\QuarantineUpload;
use App\Modules\Ingest\Services\QuarantineUploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class AdminAssetController extends Controller
{
    private const METADATA = ['title', 'description', 'date_precision', 'date_earliest', 'date_latest', 'date_display'];

    public function index(Request $request): View
    {
        $user = $this->user($request);
        abort_unless($user->hasPermission('assets.view'), 403);
        $request->validate(['q' => ['nullable', 'string', 'max:200'], 'cursor' => ['nullable', 'ulid']]);
        $query = Asset::query()->with(['files', 'uploads'])->latest('id');
        if (! $user->hasPermission('assets.publish')) {
            $query->where('created_by_user_id', $user->id);
        }
        if ($search = $request->string('q')->trim()->value()) {
            $query->where(fn ($q) => $q->whereLike('title', '%'.$search.'%')->orWhereLike('accession_number', '%'.$search.'%'));
        }
        if ($cursor = $request->string('cursor')->value()) {
            $query->where('id', '<', $cursor);
        }
        $assets = $query->limit(26)->get();
        $hasMore = $assets->count() > 25;
        $assets = $assets->take(25);
        $nextCursor = $hasMore ? $assets->last()?->id : null;

        return view('admin.assets.index', compact('assets', 'nextCursor'));
    }

    public function store(Request $request, QuarantineUploadService $uploads): RedirectResponse|JsonResponse
    {
        $user = $this->user($request);
        abort_unless($user->hasPermission('assets.view') && $user->hasPermission('assets.create'), 403);
        $request->validate(['files' => ['required', 'array', 'min:1', 'max:'.config('ingest.max_batch_upload_files')], 'files.*' => ['required', 'file']]);
        $results = [];
        foreach ($request->file('files', []) as $file) {
            $asset = null;
            try {
                $uploads->validate($file);
                $asset = Asset::query()->create(['accession_number' => 'FA-'.str()->ulid(), 'title' => mb_substr(pathinfo(basename($file->getClientOriginalName()), PATHINFO_FILENAME), 0, 255), 'created_by_user_id' => $user->id]);
                $uploads->quarantine($asset, $file, $user->id);
                $results[] = ['name' => $file->getClientOriginalName(), 'ok' => true, 'url' => route('admin.assets.show', $asset)];
            } catch (ValidationException $exception) {
                $results[] = ['name' => $file->getClientOriginalName(), 'ok' => false, 'error' => implode(' ', array_merge(...array_values($exception->errors())))];
            } catch (Throwable $exception) {
                $reference = (string) str()->uuid();
                Log::error('Upload acceptance failed.', ['reference' => $reference, 'exception_type' => $exception::class]);
                $results[] = ['name' => $file->getClientOriginalName(), 'ok' => false, 'error' => 'Opslaan mislukt. Controleer opslag en database. Referentie: '.$reference];
            }
            if ($asset !== null && ! $asset->uploads()->exists()) {
                $asset->delete();
            }
        }
        if ($request->expectsJson()) {
            return response()->json(['results' => $results], collect($results)->every('ok') ? 201 : 422);
        }

        return redirect()->route('admin.assets.index')->with('upload_results', $results);
    }

    public function show(Request $request, Asset $asset): View
    {
        $this->authorize('view', $asset);
        $asset->load(['files', 'tags', 'rights', 'uploads']);
        $events = $asset->auditEvents()->latest('id')->limit(50)->get();

        return view('admin.assets.show', compact('asset', 'events'));
    }

    public function update(Request $request, Asset $asset): RedirectResponse
    {
        $this->authorize('update', $asset);
        $data = $request->validate([
            'lock_version' => ['required', 'integer', 'min:1'],
            'title' => ['required', 'string', 'max:255'], 'description' => ['nullable', 'string', 'max:10000'],
            'date_precision' => ['required', Rule::in(['unknown', 'exact', 'circa', 'year', 'range', 'before', 'after', 'decade'])],
            'date_earliest' => ['nullable', 'date_format:Y-m-d'],
            'date_latest' => ['nullable', 'date_format:Y-m-d'],
            'date_display' => ['nullable', 'string', 'max:255'],
            'tags' => ['nullable', 'string', 'max:2000'],
            'rights_holder' => ['nullable', 'string', 'max:255'],
            'rights_status' => ['required', Rule::in(['unverified', 'verified', 'disputed'])],
            'rights_note' => ['nullable', 'string', 'max:10000'],
        ]);
        $precision = $data['date_precision'];
        $earliest = $data['date_earliest'] ?? null;
        $latest = $data['date_latest'] ?? null;
        if ($precision === 'unknown' && ($earliest !== null || $latest !== null)) {
            throw ValidationException::withMessages(['date_precision' => 'Wis de datums bij een onbekende datering.']);
        }
        if ($precision !== 'unknown' && ($precision === 'before' ? $latest === null : $earliest === null)) {
            throw ValidationException::withMessages(['date_earliest' => 'Vul de vereiste datum in (bij Vóór: de einddatum).']);
        }
        if ($precision === 'range' && $latest === null) {
            throw ValidationException::withMessages(['date_latest' => 'Een bereik vereist beide datums.']);
        }
        if ($precision === 'year' || $precision === 'decade') {
            $year = (int) substr((string) $earliest, 0, 4);
            if ($precision === 'decade') {
                $year = intdiv($year, 10) * 10;
            }
            if ($year < 1 || $year > ($precision === 'decade' ? 9990 : 9999)) {
                throw ValidationException::withMessages(['date_earliest' => 'Dit jaar valt buiten het ondersteunde bereik.']);
            }
            $earliest = sprintf('%04d-01-01', $year);
            $latest = sprintf('%04d-12-31', $year + ($precision === 'decade' ? 9 : 0));
        }
        if ($precision === 'exact') {
            if ($latest !== null && $latest !== $earliest) {
                throw ValidationException::withMessages(['date_latest' => 'Bij een exacte datum moeten beide datums gelijk zijn.']);
            }
            $latest = $earliest;
        }
        if (($earliest !== null && $latest !== null && $earliest > $latest) || ($precision === 'before' && $earliest !== null) || ($precision === 'after' && $latest !== null)) {
            throw ValidationException::withMessages(['date_latest' => 'Datumbereik is niet geldig voor deze datering.']);
        }
        $data['date_earliest'] = $earliest;
        $data['date_latest'] = $latest;
        $tagNames = collect(explode(',', (string) ($data['tags'] ?? '')))->map(fn ($name) => trim($name))->filter(fn ($name) => $name !== '')->unique()->values();
        if ($tagNames->count() > 20 || $tagNames->contains(fn ($name) => mb_strlen($name) > 100)) {
            throw ValidationException::withMessages(['tags' => 'Gebruik maximaal 20 tags van maximaal 100 tekens.']);
        }
        DB::transaction(function () use ($asset, $data, $tagNames, $request): void {
            $locked = Asset::query()->whereKey($asset->id)->lockForUpdate()->firstOrFail();
            if ((int) $data['lock_version'] !== $locked->lock_version) {
                throw ValidationException::withMessages(['lock_version' => 'Dit item is intussen gewijzigd. Vernieuw de pagina voordat je opnieuw opslaat.']);
            }
            $right = $locked->rights()->latest('id')->first();
            $before = ['metadata' => Arr::only($locked->attributesToArray(), self::METADATA), 'tags' => $locked->tags()->pluck('name')->all(), 'rights' => $right?->only(['rights_holder', 'verification_status', 'note'])];
            $locked->fill(Arr::only($data, self::METADATA));
            $locked->lock_version++;
            $locked->save();
            $locked->tags()->sync($tagNames->map(fn ($name) => Tag::query()->firstOrCreate(['name' => $name], ['slug' => hash('sha256', $name)])->id));
            $rightData = ['rights_holder' => $data['rights_holder'] ?? null, 'verification_status' => $data['rights_status'], 'note' => $data['rights_note'] ?? null];
            if ($right === null) {
                $locked->rights()->create($rightData);
            } else {
                $right->update($rightData);
            }
            AssetAuditEvent::query()->create(['asset_id' => $asset->id, 'actor_user_id' => $this->user($request)->id, 'event_type' => 'metadata.updated', 'details' => ['revision' => $locked->lock_version, 'before' => $before, 'after' => ['metadata' => Arr::only($locked->attributesToArray(), self::METADATA), 'tags' => $tagNames->all(), 'rights' => $rightData]]]);
        });

        return redirect()->route('admin.assets.show', $asset)->with('status', 'Metadata en rechten opgeslagen. De foto blijft privé.');
    }

    public function media(Request $request, Asset $asset, AssetFile $file, string $size): StreamedResponse
    {
        $this->authorize('view', $asset);
        abort_unless($file->asset_id === $asset->id && $file->storage_disk !== null && $file->ingest_status === 'ready_private', 404);
        $key = $file->derivatives[$size] ?? null;
        abort_unless(is_string($key), 404);
        $stream = Storage::disk($file->storage_disk)->readStream($key);
        abort_unless(is_resource($stream), 503, 'Voorbeeld tijdelijk niet beschikbaar.');

        return response()->stream(function () use ($stream): void {
            try {
                fpassthru($stream);
            } finally {
                fclose($stream);
            }
        }, 200, ['Content-Type' => 'image/jpeg', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'no-store, private']);
    }

    public function retry(Request $request, Asset $asset, QuarantineUpload $upload): RedirectResponse
    {
        $this->authorize('update', $asset);
        abort_unless($upload->asset_id === $asset->id, 404);
        DB::transaction(function () use ($upload, $asset, $request): void {
            $locked = QuarantineUpload::query()->whereKey($upload->id)->lockForUpdate()->firstOrFail();
            $stale = $locked->status === 'running' && $locked->started_at?->lt(now()->subMinutes(4));
            abort_unless($locked->status === 'failed' || $stale, 409, 'Alleen mislukte of vastgelopen verwerking kan opnieuw starten.');
            $locked->update(['status' => 'queued', 'failure_reason' => null, 'claim_token' => null]);
            Queue::connection('ingest')->push(new ProcessUpload($locked->id));
            AssetAuditEvent::query()->create(['asset_id' => $asset->id, 'actor_user_id' => $this->user($request)->id, 'event_type' => 'upload.retried', 'details' => ['upload_id' => $upload->id]]);
        });

        return back()->with('status', 'Verwerking opnieuw ingepland.');
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
