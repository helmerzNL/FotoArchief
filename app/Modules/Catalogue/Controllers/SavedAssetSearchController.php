<?php

declare(strict_types=1);

namespace App\Modules\Catalogue\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Catalogue\Models\SavedAssetSearch;
use App\Modules\Catalogue\Services\AssetSearchFilters;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class SavedAssetSearchController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:100'], 'filters' => ['required', 'array']]);
        $filters = Arr::except(Validator::make($data['filters'], AssetSearchFilters::rules())->validate(), ['cursor']);
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        DB::transaction(function () use ($user, $data, $filters): void {
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            abort_if(SavedAssetSearch::query()->where('user_id', $user->id)->count() >= 50, 422, __('daily.search_limit'));
            SavedAssetSearch::query()->create(['user_id' => $user->id, 'name' => $data['name'], 'filters' => $filters]);
        });

        return redirect()->route('admin.assets.index', $filters)->with('status', __('daily.saved'));
    }

    public function run(Request $request, SavedAssetSearch $search): RedirectResponse
    {
        abort_unless($search->user_id === $request->user()?->getAuthIdentifier(), 403);
        $filters = Validator::make($search->filters, AssetSearchFilters::rules())->validate();

        return redirect()->route('admin.assets.index', Arr::except($filters, ['cursor']));
    }

    public function destroy(Request $request, SavedAssetSearch $search): RedirectResponse
    {
        abort_unless($search->user_id === $request->user()?->getAuthIdentifier(), 403);
        $request->validate(['confirm' => ['accepted']]);
        $search->delete();

        return redirect()->route('admin.assets.index')->with('status', __('daily.saved'));
    }
}
