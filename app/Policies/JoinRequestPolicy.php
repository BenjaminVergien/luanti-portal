<?php

namespace App\Policies;

use App\Enums\JoinRequestStatus;
use App\Models\JoinRequest;
use App\Models\User;

class JoinRequestPolicy
{
    public function create(User $user): bool
    {
        return $user->is_active && $user->hasVerifiedEmail();
    }

    public function decide(User $user, JoinRequest $joinRequest): bool
    {
        return $joinRequest->status === JoinRequestStatus::Pending && $user->manages($joinRequest->organization);
    }

    public function cancel(User $user, JoinRequest $joinRequest): bool
    {
        return $user->is_active && $user->hasVerifiedEmail() && $user->id === $joinRequest->user_id && $joinRequest->status === JoinRequestStatus::Pending;
    }
}
