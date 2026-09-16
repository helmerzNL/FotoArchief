<?php

declare(strict_types=1);

namespace App\Modules\DataExchange\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Catalogue\Models\Asset;
use App\Modules\DataExchange\Models\DataExport;
use App\Modules\DataExchange\Models\MetadataImport;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ExchangeController extends Controller
{
    public function index(Request $request): View
    {
        $user = $this->user($request);
        abort_unless($user->hasPermission('assets.view'), 403);
        $imports = MetadataImport::query()->where('created_by_user_id', $user->id)->latest('id')->limit(20)->get();
        $exports = DataExport::query()->where('created_by_user_id', $user->id)->latest('id')->limit(20)->get();
        $assets = collect();
        if ($user->hasPermission('exports.create')) {
            $query = Asset::query()->latest('id');
            if (! $user->hasPermission('assets.publish')) {
                $query->where('created_by_user_id', $user->id);
            }
            $assets = $query->limit(100)->get();
        }

        return view('exchange.index', compact('imports', 'exports', 'assets'));
    }

    protected function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
