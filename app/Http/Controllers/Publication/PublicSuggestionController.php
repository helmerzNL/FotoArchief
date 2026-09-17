<?php

declare(strict_types=1);

namespace App\Http\Controllers\Publication;

use App\Http\Controllers\Controller;
use App\Modules\Publication\Models\AssetSuggestion;
use App\Modules\Publication\Models\Publication;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Anonymous visitor submissions for a currently public photo. Rate-limited by
 * the `throttle` route middleware and a honeypot field; nothing here ever
 * touches asset metadata directly (see {@see StaffSuggestionController}).
 *
 * {@see Publication::resolveRouteBinding()} already 404s a private, embargoed
 * or revoked photo before this action runs, so a suggestion can only ever be
 * filed against a photo the visitor was actually allowed to see.
 */
class PublicSuggestionController extends Controller
{
    public function store(Request $request, Publication $publication): RedirectResponse
    {
        $data = $request->validate([
            'suggestion_type' => ['required', Rule::in(['correction', 'identification'])],
            'message' => ['required', 'string', 'min:5', 'max:2000'],
            'submitter_name' => ['nullable', 'string', 'max:200'],
            'submitter_email' => ['nullable', 'email', 'max:255'],
            // Honeypot: a genuine visitor never fills in this hidden field.
            'website' => ['prohibited'],
        ]);

        AssetSuggestion::query()->create([
            'asset_id' => $publication->asset_id,
            'publication_id' => $publication->id,
            'suggestion_type' => $data['suggestion_type'],
            'message' => $data['message'],
            'submitter_name' => $data['submitter_name'] ?? null,
            'submitter_email' => $data['submitter_email'] ?? null,
            'ip_hash' => hash('sha256', $request->ip().config('app.key')),
            'status' => 'pending',
        ]);

        return redirect()->route('public.photo', $publication)->with('status', __('publication.generated.t_aae69ae1e08aeab1'));
    }
}
