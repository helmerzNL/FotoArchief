<?php

declare(strict_types=1);

namespace App\Http\Controllers\Publication;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Catalogue\Models\Asset;
use App\Modules\Ingest\Models\AssetAuditEvent;
use App\Modules\Publication\Models\Publication;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Staff-only draft/review/published/revoked workflow. Publishing and revoking
 * require `assets.publish`; submitting a draft for review only requires the
 * ordinary `assets.update` permission already used for metadata edits.
 */
class StaffPublicationController extends Controller
{
    public function index(Request $request): View
    {
        $user = $this->user($request);
        abort_unless($user->hasPermission('assets.view'), 403);
        $query = Asset::query()->with(['publication', 'files', 'rights'])->latest('id');
        if (! $user->hasPermission('assets.publish')) {
            $query->where('created_by_user_id', $user->id);
        }
        if ($status = $request->string('status')->value()) {
            $query->whereHas('publication', fn ($q) => $q->where('status', $status));
        }
        $assets = $query->limit(25)->get();

        return view('admin.publications.index', compact('assets'));
    }

    public function show(Request $request, Asset $asset): View
    {
        $user = $this->user($request);
        abort_unless($user->hasPermission('assets.view') && ($asset->created_by_user_id === $user->id || $user->hasPermission('assets.publish')), 403);
        $asset->load(['files', 'rights', 'publication']);
        $events = $asset->auditEvents()->whereIn('event_type', ['publication.submitted', 'publication.published', 'publication.revoked', 'publication.rejected'])->latest('id')->limit(20)->get();

        return view('admin.publications.show', compact('asset', 'events'));
    }

    public function submit(Request $request, Asset $asset): RedirectResponse
    {
        $user = $this->user($request);
        abort_unless($user->hasPermission('assets.view') && $user->hasPermission('assets.update') && ($asset->created_by_user_id === $user->id || $user->hasPermission('assets.publish')), 403);
        $data = $request->validate([
            'privacy_cleared' => ['required', 'boolean', 'accepted'],
            'download_policy' => ['required', Rule::in(['none', 'preview_only'])],
            'credit_line' => ['nullable', 'string', 'max:500'],
            'embargo_until' => ['nullable', 'date_format:Y-m-d'],
        ]);
        if (! $asset->rights()->where('verification_status', 'verified')->exists()) {
            throw ValidationException::withMessages(['privacy_cleared' => __('publication.generated.t_f4bbb39c49e20153')]);
        }
        if (! $asset->files()->where('ingest_status', 'ready_private')->where('scanner_status', 'clean')->exists()) {
            throw ValidationException::withMessages(['privacy_cleared' => __('publication.generated.t_60b3dfa76442d7e0')]);
        }
        DB::transaction(function () use ($asset, $data, $user): void {
            $publication = Publication::query()->firstOrCreate(['asset_id' => $asset->id]);
            abort_if($publication->status === 'published', 409, __('publication.generated.t_84ce5a3fcb66b807'));
            $publication->update([
                'status' => 'in_review',
                'requested_by_user_id' => $user->id,
                'submitted_at' => now(),
                'privacy_cleared' => (bool) $data['privacy_cleared'],
                'download_policy' => $data['download_policy'],
                'credit_line' => $data['credit_line'] ?? null,
                'embargo_until' => $data['embargo_until'] ?? null,
                'reject_reason' => null,
            ]);
            AssetAuditEvent::query()->create(['asset_id' => $asset->id, 'actor_user_id' => $user->id, 'event_type' => 'publication.submitted', 'details' => ['publication_id' => $publication->id]]);
        });

        return redirect()->route('admin.publications.show', $asset)->with('status', __('publication.generated.t_4e5417f584a7ba92'));
    }

    public function publish(Request $request, Asset $asset): RedirectResponse
    {
        $user = $this->user($request);
        abort_unless($user->hasPermission('assets.publish'), 403);
        DB::transaction(function () use ($asset, $user): void {
            $publication = Publication::query()->where('asset_id', $asset->id)->lockForUpdate()->first();
            abort_unless($publication !== null && $publication->status === 'in_review', 409, __('publication.generated.t_4d8f1538488bec0e'));
            abort_unless($publication->privacy_cleared, 409, __('publication.generated.t_e7c0bd249bb1c8f3'));
            $locked = Asset::query()->whereKey($asset->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->rights()->where('verification_status', 'verified')->exists(), 409, __('publication.generated.t_65b44ea637cca0bd'));
            abort_unless($locked->files()->where('ingest_status', 'ready_private')->where('scanner_status', 'clean')->exists(), 409, __('publication.generated.t_a3e8eaf0d5f878d8'));
            $publication->update([
                'permalink_slug' => $publication->permalink_slug ?? $this->uniqueSlug($locked),
                'status' => 'published',
                'reviewed_by_user_id' => $user->id,
                'reviewed_at' => now(),
                'published_at' => now(),
                'revoked_at' => null,
                'revoked_reason' => null,
                'published_lock_version' => $locked->lock_version,
            ]);
            AssetAuditEvent::query()->create(['asset_id' => $asset->id, 'actor_user_id' => $user->id, 'event_type' => 'publication.published', 'details' => ['publication_id' => $publication->id, 'lock_version' => $locked->lock_version]]);
        });

        return redirect()->route('admin.publications.show', $asset)->with('status', __('publication.generated.t_98da200bdec7726d'));
    }

    public function reject(Request $request, Asset $asset): RedirectResponse
    {
        $user = $this->user($request);
        abort_unless($user->hasPermission('assets.publish'), 403);
        $data = $request->validate(['reject_reason' => ['required', 'string', 'max:2000']]);
        DB::transaction(function () use ($asset, $user, $data): void {
            $publication = Publication::query()->where('asset_id', $asset->id)->lockForUpdate()->first();
            abort_unless($publication !== null && $publication->status === 'in_review', 409, __('publication.generated.t_b7de418a87e289e8'));
            $publication->update(['status' => 'draft', 'reviewed_by_user_id' => $user->id, 'reviewed_at' => now(), 'reject_reason' => $data['reject_reason']]);
            AssetAuditEvent::query()->create(['asset_id' => $asset->id, 'actor_user_id' => $user->id, 'event_type' => 'publication.rejected', 'details' => ['publication_id' => $publication->id, 'reason' => $data['reject_reason']]]);
        });

        return redirect()->route('admin.publications.show', $asset)->with('status', __('publication.generated.t_140892551fe695aa'));
    }

    public function revoke(Request $request, Asset $asset): RedirectResponse
    {
        $user = $this->user($request);
        abort_unless($user->hasPermission('assets.publish'), 403);
        $data = $request->validate(['revoked_reason' => ['required', 'string', 'max:2000']]);
        DB::transaction(function () use ($asset, $user, $data): void {
            $publication = Publication::query()->where('asset_id', $asset->id)->lockForUpdate()->first();
            abort_unless($publication !== null && $publication->status === 'published', 409, __('publication.generated.t_2f041838d1d96b23'));
            // Revocation must take effect immediately: status flips away from
            // "published" in the same predicate every public route reads.
            $publication->update(['status' => 'revoked', 'revoked_at' => now(), 'revoked_reason' => $data['revoked_reason']]);
            AssetAuditEvent::query()->create(['asset_id' => $asset->id, 'actor_user_id' => $user->id, 'event_type' => 'publication.revoked', 'details' => ['publication_id' => $publication->id, 'reason' => $data['revoked_reason']]]);
        });

        return redirect()->route('admin.publications.show', $asset)->with('status', __('publication.generated.t_23fb565923a1ee70'));
    }

    private function uniqueSlug(Asset $asset): string
    {
        $base = Str::slug($asset->accession_number);
        $slug = $base;
        $suffix = 1;
        while (Publication::query()->where('permalink_slug', $slug)->exists()) {
            $slug = $base.'-'.(++$suffix);
        }

        return $slug;
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
