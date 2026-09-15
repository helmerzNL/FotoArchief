<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Modules\Catalogue\Models\Asset;

class AssetPolicy
{
    public function view(User $user, Asset $asset): bool
    {
        return $user->hasPermission('assets.view') && ($asset->created_by_user_id === $user->id || $user->hasPermission('assets.publish'));
    }

    public function update(User $user, Asset $asset): bool
    {
        return $this->view($user, $asset) && $user->hasPermission('assets.update');
    }

    public function upload(User $user, Asset $asset): bool
    {
        return $this->update($user, $asset) && $user->hasPermission('assets.create');
    }
}
