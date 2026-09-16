<?php

declare(strict_types=1);

namespace App\Http\Controllers\Publication;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Ingest\Models\AssetAuditEvent;
use App\Modules\Publication\Models\AssetSuggestion;
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
 */
class StaffSuggestionController extends Controller
{
    public function index(Request $request): View
    {
        $user = $this->user($request);
        abort_unless($user->hasPermission('assets.view'), 403);
        $status = $request->string('status')->value() ?: 'pending';
        $suggestions = AssetSuggestion::query()->with(['asset', 'publication'])
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->latest('id')->limit(50)->get();

        return view('admin.suggestions.index', compact('suggestions', 'status'));
    }

    public function show(Request $request, AssetSuggestion $suggestion): View
    {
        $user = $this->user($request);
        abort_unless($user->hasPermission('assets.view'), 403);
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
        abort_unless($user->hasPermission('assets.update'), 403);
        $data = $request->validate(['moderator_note' => ['nullable', 'string', 'max:2000']]);
        DB::transaction(function () use ($suggestion, $user, $decision, $data): void {
            $locked = AssetSuggestion::query()->whereKey($suggestion->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->status === 'pending', 409, 'Deze suggestie is al beoordeeld.');
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

        return redirect()->route('admin.suggestions.index')->with('status', 'Suggestie beoordeeld.');
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
