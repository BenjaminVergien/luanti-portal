<?php

namespace App\Policies;

use App\Models\LuantiWorldTemplate;
use App\Models\User;

class LuantiWorldTemplatePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->fresh()?->isGlobalAdmin() ?? false;
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, LuantiWorldTemplate $world): bool
    {
        return $this->viewAny($user);
    }
}
