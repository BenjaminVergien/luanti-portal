<?php

namespace App\Policies;

use App\Models\LuantiServer;
use App\Models\User;

class LuantiServerPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->fresh()?->isGlobalAdmin() ?? false;
    }

    public function view(User $user, LuantiServer $luantiServer): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, LuantiServer $luantiServer): bool
    {
        return $this->viewAny($user);
    }

    public function delete(User $user, LuantiServer $luantiServer): bool
    {
        return $this->viewAny($user);
    }
}
