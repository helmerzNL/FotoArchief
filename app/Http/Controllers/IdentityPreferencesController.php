<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use App\Modules\Identity\InterfacePreferences;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IdentityPreferencesController extends Controller
{
    public function update(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        $stored = InterfacePreferences::update($user, $request->validate(InterfacePreferences::rules()));

        return response()->json($stored === [] ? (object) [] : $stored);
    }
}
