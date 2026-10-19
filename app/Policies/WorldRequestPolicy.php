<?php

namespace App\Policies;

use App\Enums\WorldRequestStatus;
use App\Models\Organization;
use App\Models\User;
use App\Models\WorldRequest;

class WorldRequestPolicy
{
    public function viewAny(User $user, ?Organization $organization = null): bool
    {
        $user = $user->fresh();

        return $user !== null && $user->is_active && $user->hasVerifiedEmail()
            && ($organization === null ? $user->isInternal() : $user->canAccessTenant($organization));
    }

    public function view(User $user, WorldRequest $request): bool
    {
        $user = $user->fresh();

        return $user !== null && ($user->isInternal() || $this->viewAny($user, $request->organization));
    }

    public function create(User $user, Organization $organization): bool
    {
        $user = $user->fresh();

        return $user !== null && $user->is_active && $user->hasVerifiedEmail()
            && $user->memberships()->where('organization_id', $organization->id)->exists();
    }

    public function update(User $user, WorldRequest $request): bool
    {
        $user = $user->fresh();
        $request = $request->fresh();

        return $user !== null && $request !== null && ! in_array($request->status, [WorldRequestStatus::Rejected, WorldRequestStatus::Terminated], true)
            && $this->view($user, $request)
            && ($user->isInternal() || $user->manages($request->organization) || $user->id === $request->user_id);
    }

    public function decide(User $user, WorldRequest $request): bool
    {
        return ($user->fresh()?->isInternal() ?? false) && $request->fresh()?->status === WorldRequestStatus::Pending;
    }

    public function terminate(User $user, WorldRequest $request): bool
    {
        $user = $user->fresh();
        $request = $request->fresh();

        return $user !== null && $request !== null && $user->is_active && $user->hasVerifiedEmail()
            && $request->status !== WorldRequestStatus::Terminated && $this->view($user, $request)
            && ($user->isInternal() || $user->manages($request->organization) || $user->id === $request->user_id);
    }

    public function manageAccounts(User $user, WorldRequest $request): bool
    {
        $user = $user->fresh();
        $request = $request->fresh();
        if ($user === null || $request === null || ! $user->is_active || ! $user->hasVerifiedEmail()
            || $request->status === WorldRequestStatus::Terminated) {
            return false;
        }
        if ($user->isInternal()) {
            return true;
        }

        return $user->memberships()->where('organization_id', $request->organization_id)->exists()
            && ($user->id === $request->user_id || $user->manages($request->organization))
            && ($request->status === WorldRequestStatus::Approved || $request->approved_access !== null || $request->luantiAccounts()->exists());
    }
}
