<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\View\View;

class IdentitySecurityController extends Controller
{
    public function show(Request $request): View
    {
        $user = $request->user();
        abort_if($user === null, 403);

        return view('identity.security.show', [
            'user' => $user->load(['passkeys' => fn ($query) => $query->latest('created_at'), 'recoveryCodes']),
        ]);
    }
}
