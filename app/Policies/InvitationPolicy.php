<?php

namespace App\Policies;

use App\Enums\InternalRole;
use App\Enums\MembershipRole;
use App\Models\Invitation;
use App\Models\Organization;
use App\Models\User;

class InvitationPolicy
{
    public function create(User $user, ?Organization $organization, ?MembershipRole $membershipRole, ?InternalRole $internalRole): bool
    {
        if ($organization === null) {
            return $membershipRole === null && $internalRole !== null && $user->isGlobalAdmin();
        }

        return $internalRole === null && $membershipRole !== null
            && (new OrganizationMembershipPolicy)->assign($user, $organization, $membershipRole);
    }

    public function revoke(User $user, Invitation $invitation): bool
    {
        return $invitation->isPending() && $this->create($user, $invitation->organization, $invitation->membership_role, $invitation->internal_role);
    }
}
