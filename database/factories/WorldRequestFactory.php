<?php

namespace Database\Factories;

use App\Enums\WorldRequestStatus;
use App\Models\LuantiWorld;
use App\Models\Organization;
use App\Models\User;
use App\Models\WorldRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<WorldRequest> */
class WorldRequestFactory extends Factory
{
    /** @return array{organization_id: OrganizationFactory, user_id: UserFactory, luanti_world_id: LuantiWorldFactory, name: string, start_date: string, end_date: string, participant_count: int, status: WorldRequestStatus, revision: int} */
    public function definition(): array
    {
        return ['organization_id' => Organization::factory(), 'user_id' => User::factory(), 'luanti_world_id' => LuantiWorld::factory(),
            'name' => fake()->words(3, true), 'start_date' => now('Europe/Berlin')->toDateString(),
            'end_date' => now('Europe/Berlin')->addDays(7)->toDateString(), 'participant_count' => 12,
            'status' => WorldRequestStatus::Pending, 'revision' => 1];
    }

    public function approved(): static
    {
        return $this->state(fn (): array => ['status' => WorldRequestStatus::Approved,
            'decided_by' => User::factory()->caseworker(), 'decided_at' => now()]);
    }

    public function rejected(): static
    {
        return $this->state(fn (): array => ['status' => WorldRequestStatus::Rejected,
            'decided_by' => User::factory()->caseworker(), 'decided_at' => now()]);
    }
}
