<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isGlobalAdmin();
    }

    public function update(User $user, User $target): bool
    {
        return $user->isGlobalAdmin();
    }
}
