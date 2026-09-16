<?php

declare(strict_types=1);

namespace App\Modules\Catalogue\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalogue\Models\Collection;
use App\Modules\Catalogue\Models\Contributor;
use App\Modules\Catalogue\Models\Location;
use App\Modules\Catalogue\Models\Person;
use App\Modules\Catalogue\Models\Source;
use App\Modules\Catalogue\Models\Tag;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CatalogueDashboardController extends Controller
{
    public function index(Request $request): View
    {
        $stats = [
            'collections_count' => Collection::query()->count(),
            'people_count' => Person::query()->count(),
            'locations_count' => Location::query()->count(),
            'sources_count' => Source::query()->count(),
            'contributors_count' => Contributor::query()->count(),
            'tags_count' => Tag::query()->count(),
        ];

        return view('catalogue.index', compact('stats'));
    }
}
