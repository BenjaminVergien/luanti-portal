<?php

namespace App\Policies;

use App\Models\LuantiWorld;
use App\Models\User;

class LuantiWorldPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->fresh()?->isGlobalAdmin() ?? false;
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, LuantiWorld $world): bool
    {
        return $this->viewAny($user);
    }
}
