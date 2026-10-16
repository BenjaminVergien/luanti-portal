<?php

namespace Database\Factories;

use App\Enums\WorldRequestStatus;
use App\Models\LuantiGameInstance;
use App\Models\LuantiWorldTemplate;
use App\Models\Organization;
use App\Models\User;
use App\Models\WorldRequest;
use App\Services\LuantiAccounts;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<WorldRequest> */
class WorldRequestFactory extends Factory
{
    /** @return array{organization_id: OrganizationFactory, user_id: UserFactory, luanti_world_template_id: LuantiWorldTemplateFactory, name: string, start_date: string, end_date: string, participant_count: int, status: WorldRequestStatus, revision: int} */
    public function definition(): array
    {
        return ['organization_id' => Organization::factory(), 'user_id' => User::factory(), 'luanti_world_template_id' => LuantiWorldTemplate::factory(),
            'name' => fake()->words(3, true), 'start_date' => now('Europe/Berlin')->toDateString(),
            'end_date' => now('Europe/Berlin')->addDays(7)->toDateString(), 'participant_count' => 12,
            'status' => WorldRequestStatus::Pending, 'revision' => 1];
    }

    public function approved(): static
    {
        return $this->state(fn (): array => ['status' => WorldRequestStatus::Approved,
            'decided_by' => User::factory()->caseworker(), 'decided_at' => now()])
            ->afterCreating(function (WorldRequest $request): void {
                $instance = LuantiGameInstance::factory()->create(['world_request_id' => $request->id, 'is_prepared' => true, 'state' => 'running']);
                $request->forceFill(['current_game_instance_id' => $instance->id])->save();
                $request->forceFill(['approved_access' => app(LuantiAccounts::class)->snapshot($request)])->save();
            });
    }

    public function rejected(): static
    {
        return $this->state(fn (): array => ['status' => WorldRequestStatus::Rejected,
            'decided_by' => User::factory()->caseworker(), 'decided_at' => now()]);
    }
}
