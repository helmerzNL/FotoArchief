<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use App\Modules\Catalogue\Models\Asset;
use App\Modules\Catalogue\Models\AssetFile;
use App\Modules\Catalogue\Models\Collection;
use App\Modules\Catalogue\Models\Location;
use App\Modules\Catalogue\Models\Person;
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
        $data = $request->validate([
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
        ]);

        $query = Asset::query()->with(['files', 'uploads'])->latest('id');

        if (! $user->hasPermission('assets.publish')) {
            $query->where('created_by_user_id', $user->id);
        }

        if ($search = $request->string('q')->trim()->value()) {
            $query->where(fn ($q) => $q->whereLike('title', '%'.$search.'%')->orWhereLike('accession_number', '%'.$search.'%')->orWhereLike('description', '%'.$search.'%'));
        }

        if (! empty($data['date_from'])) {
            $query->where(fn ($q) => $q->where('date_latest', '>=', $data['date_from'])->orWhere('date_earliest', '>=', $data['date_from']));
        }

        if (! empty($data['date_to'])) {
            $query->where(fn ($q) => $q->where('date_earliest', '<=', $data['date_to'])->orWhere('date_latest', '<=', $data['date_to']));
        }

        if (! empty($data['date_precision'])) {
            $query->where('date_precision', $data['date_precision']);
        }

        if (! empty($data['person_id'])) {
            $query->whereHas('people', fn ($q) => $q->where('people.id', $data['person_id']));
        }

        if (! empty($data['location_id'])) {
            $query->whereHas('locations', fn ($q) => $q->where('locations.id', $data['location_id']));
        }

        if (! empty($data['collection_id'])) {
            $query->whereHas('collections', fn ($q) => $q->where('collections.id', $data['collection_id']));
        }

        if (! empty($data['tag_id'])) {
            $query->whereHas('tags', fn ($q) => $q->where('tags.id', $data['tag_id']));
        }

        if (! empty($data['rights_status'])) {
            $query->whereHas('rights', fn ($q) => $q->where('verification_status', $data['rights_status']));
        }

        if (! empty($data['catalogue_status'])) {
            $query->where('catalogue_status', $data['catalogue_status']);
        }

        if ($cursor = $request->string('cursor')->value()) {
            $query->where('id', '<', $cursor);
        }

        $assets = $query->limit(26)->get();
        $hasMore = $assets->count() > 25;
        $assets = $assets->take(25);
        $nextCursor = $hasMore ? $assets->last()?->id : null;

        $filterCollections = Collection::query()->orderBy('title')->get();
        $filterTags = Tag::query()->orderBy('name')->get();
        $filterPeople = Person::query()->orderBy('sort_name')->limit(100)->get();
        $filterLocations = Location::query()->orderBy('name')->limit(100)->get();

        return view('admin.assets.index', compact(
            'assets',
            'nextCursor',
            'filterCollections',
            'filterTags',
            'filterPeople',
            'filterLocations'
        ));
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
                Log::error(__('catalogue.generated.t_6c53ada851a7b566'), ['reference' => $reference, 'exception_type' => $exception::class]);
                $results[] = ['name' => $file->getClientOriginalName(), 'ok' => false, 'error' => __('catalogue.generated.t_a908dfaf3b1f0ddd').$reference];
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
        $asset->load(['files', 'tags', 'rights', 'uploads', 'people', 'locations', 'collections', 'sources', 'contributors']);
        $events = $asset->auditEvents()->latest('id')->limit(50)->get();
        $aiRuns = $asset->aiRuns()->with(['suggestions' => fn ($query) => $query->oldest('id')])
            ->latest('created_at')->latest('id')->paginate(10, ['*'], 'ai_page')->fragment('ai-results');

        return view('admin.assets.show', compact('asset', 'events', 'aiRuns'));
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
            throw ValidationException::withMessages(['date_precision' => __('catalogue.generated.t_caa7af0e5a5061f2')]);
        }
        if ($precision !== 'unknown' && ($precision === 'before' ? $latest === null : $earliest === null)) {
            throw ValidationException::withMessages(['date_earliest' => __('catalogue.generated.t_50c23821b0a95587')]);
        }
        if ($precision === 'range' && $latest === null) {
            throw ValidationException::withMessages(['date_latest' => __('catalogue.generated.t_de4038053bc9bc52')]);
        }
        if ($precision === 'year' || $precision === 'decade') {
            $year = (int) substr((string) $earliest, 0, 4);
            if ($precision === 'decade') {
                $year = intdiv($year, 10) * 10;
            }
            if ($year < 1 || $year > ($precision === 'decade' ? 9990 : 9999)) {
                throw ValidationException::withMessages(['date_earliest' => __('catalogue.generated.t_efeb1e0a30087ed7')]);
            }
            $earliest = sprintf('%04d-01-01', $year);
            $latest = sprintf('%04d-12-31', $year + ($precision === 'decade' ? 9 : 0));
        }
        if ($precision === 'exact') {
            if ($latest !== null && $latest !== $earliest) {
                throw ValidationException::withMessages(['date_latest' => __('catalogue.generated.t_60d9ebdc5cba051a')]);
            }
            $latest = $earliest;
        }
        if (($earliest !== null && $latest !== null && $earliest > $latest) || ($precision === 'before' && $earliest !== null) || ($precision === 'after' && $latest !== null)) {
            throw ValidationException::withMessages(['date_latest' => __('catalogue.generated.t_782c5d380a998eb0')]);
        }
        $data['date_earliest'] = $earliest;
        $data['date_latest'] = $latest;
        $tagNames = collect(explode(',', (string) ($data['tags'] ?? '')))->map(fn ($name) => trim($name))->filter(fn ($name) => $name !== '')->unique()->values();
        if ($tagNames->count() > 20 || $tagNames->contains(fn ($name) => mb_strlen($name) > 100)) {
            throw ValidationException::withMessages(['tags' => __('catalogue.generated.t_40b8e381d73aa3d2')]);
        }
        DB::transaction(function () use ($asset, $data, $tagNames, $request): void {
            $locked = Asset::query()->whereKey($asset->id)->lockForUpdate()->firstOrFail();
            if ((int) $data['lock_version'] !== $locked->lock_version) {
                throw ValidationException::withMessages(['lock_version' => __('catalogue.generated.t_dae19fcb83529b1e')]);
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

        return redirect()->route('admin.assets.show', $asset)->with('status', __('catalogue.generated.t_3ffcd1a97bc06c4e'));
    }

    public function media(Request $request, Asset $asset, AssetFile $file, string $size): StreamedResponse
    {
        $this->authorize('view', $asset);
        abort_unless($file->asset_id === $asset->id && $file->storage_disk !== null && $file->ingest_status === 'ready_private', 404);
        $key = $file->derivatives[$size] ?? null;
        abort_unless(is_string($key), 404);
        $stream = Storage::disk($file->storage_disk)->readStream($key);
        abort_unless(is_resource($stream), 503, __('catalogue.generated.t_194a9c2224822d03'));

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
            abort_unless($locked->status === 'failed' || $stale, 409, __('catalogue.generated.t_29c9a7a2008777d8'));
            $locked->update(['status' => 'queued', 'failure_reason' => null, 'claim_token' => null]);
            Queue::connection('ingest')->push(new ProcessUpload($locked->id));
            AssetAuditEvent::query()->create(['asset_id' => $asset->id, 'actor_user_id' => $this->user($request)->id, 'event_type' => 'upload.retried', 'details' => ['upload_id' => $upload->id]]);
        });

        return redirect()->route('admin.assets.show', $asset)->with('status', __('catalogue.generated.t_85746463158a4b56'));
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
