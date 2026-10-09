<?php

namespace Database\Factories;

use App\Enums\MembershipRole;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class OrganizationMembershipFactory extends Factory
{
    public function definition(): array
    {
        return ['organization_id' => Organization::factory(), 'user_id' => User::factory(), 'role' => MembershipRole::Staff];
    }

    public function manager(): static
    {
        return $this->state(fn (): array => ['role' => MembershipRole::Manager]);
    }
}
