<?php

declare(strict_types=1);

namespace App\Modules\Catalogue\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Catalogue\Models\Asset;
use App\Modules\Catalogue\Models\Collection;
use App\Modules\Catalogue\Models\Tag;
use App\Modules\Catalogue\Services\BulkMetadataService;
use App\Modules\Catalogue\Services\ReviewReceipt;
use Illuminate\Http\Request;
use Illuminate\View\View;

class BulkAssetController extends Controller
{
    public function create(Request $request): View
    {
        $data = $request->validate(['asset_ids' => ['required', 'array', 'min:1', 'max:25'], 'asset_ids.*' => ['required', 'ulid', 'distinct']]);
        $assets = Asset::query()->whereIn('id', $data['asset_ids'])->with(['tags', 'collections', 'rights'])->get();
        abort_unless($assets->count() === count($data['asset_ids']), 404);
        foreach ($assets as $asset) {
            $this->authorize('update', $asset);
        }

        return view('catalogue.bulk.confirm', ['assets' => $assets, 'collections' => Collection::query()->orderBy('title')->get(), 'tags' => Tag::query()->orderBy('name')->get()]);
    }

    public function preview(Request $request, BulkMetadataService $service, ReviewReceipt $receipts): View
    {
        $data = $service->validate($request->all());
        $rows = $service->preview($this->user($request), $data);
        $receipt = $receipts->issue($this->user($request), 'bulk-metadata', $data);

        return view('catalogue.bulk.preview', compact('rows', 'receipt'));
    }

    public function store(Request $request, BulkMetadataService $service, ReviewReceipt $receipts): View
    {
        $data = $request->validate(['receipt' => ['required', 'string', 'max:100000'], 'confirm' => ['accepted']]);
        $payload = $receipts->read($this->user($request), 'bulk-metadata', $data['receipt']);
        $results = $service->apply($this->user($request), $payload);

        return view('catalogue.bulk.results', compact('results'));
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
