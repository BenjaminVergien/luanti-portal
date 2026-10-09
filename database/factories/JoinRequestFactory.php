<?php

namespace Database\Factories;

use App\Enums\JoinRequestStatus;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class JoinRequestFactory extends Factory
{
    public function definition(): array
    {
        return ['organization_id' => Organization::factory(), 'user_id' => User::factory(), 'status' => JoinRequestStatus::Pending];
    }
}
