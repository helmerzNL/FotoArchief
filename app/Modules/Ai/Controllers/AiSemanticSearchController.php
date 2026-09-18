<?php

declare(strict_types=1);

namespace App\Modules\Ai\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Ai\Services\AiConfigurationService;
use App\Modules\Ai\Services\AiSemanticSearchService;
use App\Modules\Catalogue\Models\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\View\View;

class AiSemanticSearchController extends Controller
{
    public function __construct(
        private readonly AiSemanticSearchService $search,
        private readonly AiConfigurationService $configuration,
    ) {}

    public function __invoke(Request $request): View|RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->hasPermission('assets.view'), 403);

        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:200'], 'provider' => ['nullable', 'string', 'max:100'],
            'collection' => ['nullable', 'ulid', 'exists:collections,id'], 'mode' => ['nullable', 'in:text,semantic'],
        ]);
        $query = trim($data['q'] ?? '');
        $collection = $data['collection'] ?? null;
        if (($data['mode'] ?? 'semantic') === 'text') {
            return redirect()->route('admin.assets.index', array_filter(['q' => $query, 'collection_id' => $collection]));
        }
        $settings = $this->configuration->effective();
        $provider = (string) $request->query('provider', (string) ($settings['embeddings_provider'] ?? ''));
        $collections = Collection::query()->whereHas('assets', fn ($q) => $q->when(! $user->hasPermission('assets.publish'), fn ($q) => $q->where('created_by_user_id', $user->id)))->orderBy('title')->get(['id', 'title']);
        $results = [];
        if (trim($query) !== '') {
            $results = $this->search->searchAdmin($query, $provider, $user, 10, $collection);
        }
        $receipts = [];
        if ($user->hasPermission('catalogue.manage') || $user->hasPermission('users.manage')) {
            foreach ($results as $result) {
                $receipts[$result['asset_id']] = Crypt::encryptString(json_encode([
                    'user_id' => $user->id, 'asset_id' => $result['asset_id'], 'query' => $query,
                    'provider' => $provider, 'model_space' => $result['model_space'], 'collection_id' => $collection,
                    'expires' => time() + 3600,
                ], JSON_THROW_ON_ERROR));
            }
        }

        return view('ai.search.admin', compact('query', 'provider', 'results', 'settings', 'collections', 'collection', 'receipts'));
    }
}
