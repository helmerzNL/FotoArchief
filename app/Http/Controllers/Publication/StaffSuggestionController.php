<?php

declare(strict_types=1);

namespace App\Http\Controllers\Publication;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Catalogue\Models\Asset;
use App\Modules\Ingest\Models\AssetAuditEvent;
use App\Modules\Publication\Models\AssetSuggestion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Staff moderation of visitor-submitted suggestions. Accepting a suggestion
 * only records the moderation decision and an audit event; it never applies
 * the suggested change to the asset itself. A moderator who agrees with a
 * suggestion still edits the asset through the ordinary staff metadata form,
 * which keeps its own audit trail. This is deliberate: no auto-apply pipeline.
 *
 * Access is scoped exactly like {@see StaffPublicationController}: a
 * `viewer`/`volunteer` staff member without `assets.publish` may only see and
 * moderate suggestions filed against assets they themselves created, so they
 * can never read another owner's visitor PII (submitter name/email) or
 * moderate an asset outside their own scope. Staff with `assets.publish` see
 * and moderate everything, matching the publication workflow's own
 * ownership rule.
 */
class StaffSuggestionController extends Controller
{
    public function index(Request $request): View
    {
        $user = $this->user($request);
        abort_unless($user->hasPermission('assets.view'), 403);
        $status = $request->string('status')->value() ?: 'pending';
        // whereHas('asset') alone already excludes a suggestion whose asset
        // is soft-deleted or otherwise missing, because Asset::SoftDeletes'
        // global scope applies automatically inside the closure-less
        // relation existence check - fail closed for trashed assets with no
        // further condition needed here.
        $suggestions = AssetSuggestion::query()->with(['asset', 'publication'])
            ->whereHas('asset', function (Builder $q) use ($user): void {
                if (! $user->hasPermission('assets.publish')) {
                    $q->where('created_by_user_id', $user->id);
                }
            })
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->latest('id')->limit(50)->get();

        return view('admin.suggestions.index', compact('suggestions', 'status'));
    }

    public function show(Request $request, AssetSuggestion $suggestion): View
    {
        $user = $this->user($request);
        $asset = $this->authorizedAsset($user, $suggestion);
        $suggestion->load(['asset', 'publication', 'moderator']);

        return view('admin.suggestions.show', compact('suggestion'));
    }

    public function accept(Request $request, AssetSuggestion $suggestion): RedirectResponse
    {
        return $this->moderate($request, $suggestion, 'accepted');
    }

    public function reject(Request $request, AssetSuggestion $suggestion): RedirectResponse
    {
        return $this->moderate($request, $suggestion, 'rejected');
    }

    private function moderate(Request $request, AssetSuggestion $suggestion, string $decision): RedirectResponse
    {
        $user = $this->user($request);
        $data = $request->validate(['moderator_note' => ['nullable', 'string', 'max:2000']]);
        DB::transaction(function () use ($suggestion, $user, $decision, $data): void {
            $locked = AssetSuggestion::query()->whereKey($suggestion->id)->lockForUpdate()->firstOrFail();
            // Re-authorize inside the lock, against the asset as it stands
            // right now: ownership cannot have changed, but this keeps the
            // asset-existence (soft-delete) check and the permission check
            // atomic with the "already moderated" check below.
            $this->authorizedAsset($user, $locked, requirePermission: 'assets.update');
            abort_unless($locked->status === 'pending', 409, __('publication.generated.t_88fd00236231fe63'));
            $locked->update([
                'status' => $decision,
                'moderator_user_id' => $user->id,
                'moderated_at' => now(),
                'moderator_note' => $data['moderator_note'] ?? null,
            ]);
            AssetAuditEvent::query()->create([
                'asset_id' => $locked->asset_id,
                'actor_user_id' => $user->id,
                'event_type' => 'suggestion.'.$decision,
                'details' => ['suggestion_id' => $locked->id, 'note' => $data['moderator_note'] ?? null],
            ]);
        });

        return redirect()->route('admin.suggestions.index')->with('status', __('publication.generated.t_95fba2c11f646e1c'));
    }

    /**
     * Resolves the suggestion's asset through its default (non-trashed)
     * query so a trashed asset's suggestion 404s instead of exposing visitor
     * PII or a moderation action for an asset Operations has put in the
     * trash, then authorizes the current user against it: `assets.view` (or
     * the caller-supplied stronger permission) plus either ownership of the
     * asset or `assets.publish`, exactly like StaffPublicationController.
     */
    private function authorizedAsset(User $user, AssetSuggestion $suggestion, string $requirePermission = 'assets.view'): Asset
    {
        $asset = $suggestion->asset()->first();
        abort_unless($asset !== null, 404);
        abort_unless(
            $user->hasPermission($requirePermission) && ($asset->created_by_user_id === $user->id || $user->hasPermission('assets.publish')),
            403
        );

        return $asset;
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
