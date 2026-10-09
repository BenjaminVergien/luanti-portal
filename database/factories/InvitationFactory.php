<?php

namespace Database\Factories;

use App\Enums\MembershipRole;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class InvitationFactory extends Factory
{
    public function definition(): array
    {
        return ['email' => fake()->unique()->safeEmail(), 'organization_id' => Organization::factory(),
            'membership_role' => MembershipRole::Staff, 'invited_by' => User::factory()->globalAdmin(),
            'token_hash' => hash('sha256', Str::random(64)), 'expires_at' => now()->addDays(7)];
    }
}
