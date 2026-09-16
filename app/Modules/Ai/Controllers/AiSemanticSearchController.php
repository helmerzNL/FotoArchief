<?php

declare(strict_types=1);

namespace App\Modules\Ai\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Ai\Services\AiSemanticSearchService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AiSemanticSearchController extends Controller
{
    public function __construct(
        private readonly AiSemanticSearchService $search,
    ) {}

    public function __invoke(Request $request): View
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->hasPermission('assets.view'), 403);

        $query = (string) $request->query('q', '');
        $provider = (string) $request->query('provider', 'local');
        $results = [];
        if (trim($query) !== '') {
            $results = $this->search->searchAdmin($query, $provider, $user, 10);
        }

        return view('ai.search.admin', compact('query', 'provider', 'results'));
    }
}
