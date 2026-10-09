<?php

namespace App\Policies;

use App\Enums\MembershipRole;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;

class OrganizationMembershipPolicy
{
    public function assign(User $user, Organization $organization, MembershipRole $role): bool
    {
        return $user->isInternal() || ($role === MembershipRole::Staff && $user->manages($organization));
    }

    public function update(User $user, OrganizationMembership $membership): bool
    {
        return $user->isInternal() || ($membership->role === MembershipRole::Staff && $user->manages($membership->organization));
    }

    public function delete(User $user, OrganizationMembership $membership): bool
    {
        return $this->update($user, $membership);
    }
}
