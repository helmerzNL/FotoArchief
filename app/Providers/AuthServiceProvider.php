<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\User;
use App\Modules\Catalogue\Models\Asset;
use App\Policies\AssetPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Gate::policy(Asset::class, AssetPolicy::class);
        Gate::before(static function (User $user, string $ability): ?bool {
            return str_contains($ability, '.') && $user->hasPermission($ability) ? true : null;
        });
    }
}
