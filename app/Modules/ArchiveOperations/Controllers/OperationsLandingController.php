<?php

declare(strict_types=1);

namespace App\Modules\ArchiveOperations\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Landing page for the archive operations.
 *
 * The header used to link straight into diagnostics, which only a user holding
 * `users.manage` may open. An archivist who may use duplicates, versions,
 * processing and OCR therefore saw no way in at all and had to know the URLs by
 * heart. This landing is reachable by anyone who may open at least one
 * operation, and shows them exactly the ones they may open.
 */
class OperationsLandingController extends Controller
{
    /**
     * Any one of these is enough to have somewhere to go.
     *
     * @var list<string>
     */
    public const array ENTRY_PERMISSIONS = [
        'users.manage',
        'audit.view',
        'catalogue.manage',
        'assets.update',
        'assets.view',
    ];

    public function index(Request $request): View
    {
        $user = $request->user();
        abort_unless($user instanceof User && self::mayReachOperations($user), 403);

        return view('operations.index');
    }

    public static function mayReachOperations(User $user): bool
    {
        foreach (self::ENTRY_PERMISSIONS as $permission) {
            if ($user->hasPermission($permission)) {
                return true;
            }
        }

        return false;
    }
}
