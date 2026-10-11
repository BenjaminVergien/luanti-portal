<?php

namespace App\Policies;

use App\Enums\MembershipRole;
use App\Models\Organization;
use App\Models\User;

class OrganizationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isInternal();
    }

    public function view(User $user, Organization $organization): bool
    {
        return $user->isInternal() || $user->canAccessTenant($organization);
    }

    public function create(User $user): bool
    {
        return $user->isGlobalAdmin();
    }

    public function createForSelf(User $user): bool
    {
        return $user->is_active && $user->hasVerifiedEmail();
    }

    public function verify(User $user, Organization $organization): bool
    {
        return $user->isInternal();
    }

    public function update(User $user, Organization $organization): bool
    {
        return $user->isGlobalAdmin() || ($user->is_active && $user->hasVerifiedEmail()
            && $user->memberships()->where('organization_id', $organization->id)->where('role', MembershipRole::Manager)->exists());
    }
}
