<?php

declare(strict_types=1);

namespace App\Modules\Ai\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Catalogue\Models\Asset;
use App\Modules\Ingest\Models\AssetAuditEvent;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class AiRelevanceController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $user = $this->user($request);
        $data = $request->validate(['receipt' => ['required', 'string', 'max:10000'], 'grade' => ['required', 'integer', 'between:0,2']]);
        try {
            $receipt = json_decode(Crypt::decryptString($data['receipt']), true, 512, JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException $exception) {
            throw ValidationException::withMessages(['receipt' => __('indexwork.invalid_receipt')]);
        }
        if (! is_array($receipt) || ($receipt['user_id'] ?? null) !== $user->id
            || ! is_int($receipt['expires'] ?? null) || $receipt['expires'] < time()
            || ! is_string($receipt['asset_id'] ?? null) || ! Str::isUlid($receipt['asset_id'])
            || ! is_string($receipt['query'] ?? null) || mb_strlen($receipt['query']) > 200
            || ! is_string($receipt['provider'] ?? null) || mb_strlen($receipt['provider']) > 100
            || ! is_string($receipt['model_space'] ?? null) || mb_strlen($receipt['model_space']) > 255
            || ! array_key_exists('collection_id', $receipt)
            || ($receipt['collection_id'] !== null && (! is_string($receipt['collection_id']) || ! Str::isUlid($receipt['collection_id'])))) {
            throw ValidationException::withMessages(['receipt' => __('indexwork.invalid_receipt')]);
        }
        $asset = Asset::query()->findOrFail($receipt['asset_id']);
        Gate::forUser($user)->authorize('view', $asset);
        $key = hash('sha256', json_encode([$user->id, $asset->id, $receipt['query'], $receipt['provider'], $receipt['model_space'], $receipt['collection_id']], JSON_THROW_ON_ERROR));
        DB::transaction(function () use ($receipt, $user, $asset, $key, $data): void {
            DB::table('ai_relevance_labels')->upsert([[
                'id' => (string) Str::ulid(), 'user_id' => $user->id, 'asset_id' => $asset->id,
                'deduplication_key' => $key, 'query' => $receipt['query'], 'provider' => $receipt['provider'],
                'model_space' => $receipt['model_space'], 'collection_id' => $receipt['collection_id'],
                'grade' => $data['grade'], 'created_at' => now(), 'updated_at' => now(),
            ]], ['deduplication_key'], ['grade', 'updated_at']);
            AssetAuditEvent::query()->create([
                'asset_id' => $asset->id, 'actor_user_id' => $user->id, 'event_type' => 'ai.relevance.rated',
                'details' => ['grade' => $data['grade'], 'provider' => $receipt['provider'], 'model_space' => $receipt['model_space']],
            ]);
        });

        return redirect()->route('admin.operations.ai.search')->with('status', __('indexwork.label_saved'));
    }

    public function export(Request $request): StreamedResponse
    {
        $user = $this->user($request);
        $visible = Asset::query()->select('id')->when(! $user->hasPermission('assets.publish'), fn ($q) => $q->where('created_by_user_id', $user->id));
        $labels = DB::table('ai_relevance_labels')->where('user_id', $user->id)->whereIn('asset_id', $visible)
            ->select(['query', 'asset_id', 'provider', 'model_space', 'collection_id', 'grade', 'updated_at'])
            ->orderBy('id')->limit(10001)->get();
        if ($labels->count() > 10000) {
            throw ValidationException::withMessages(['export' => __('workbench.export_limit')]);
        }

        return response()->streamDownload(function () use ($labels): void {
            foreach ($labels as $label) {
                echo json_encode($label, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)."\n";
            }
        }, 'fotoarchief-relevance-labels.jsonl', ['Content-Type' => 'application/x-ndjson', 'Cache-Control' => 'private, no-store']);
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->hasPermission('assets.view')
            && ($user->hasPermission('catalogue.manage') || $user->hasPermission('users.manage')), 403);

        return $user;
    }
}
