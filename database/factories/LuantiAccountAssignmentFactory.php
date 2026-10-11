<?php

namespace Database\Factories;

use App\Models\LuantiAccount;
use App\Models\LuantiAccountAssignment;
use App\Models\LuantiWorld;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LuantiAccountAssignment>
 */
class LuantiAccountAssignmentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array{luanti_account_id: LuantiAccountFactory, luanti_world_id: LuantiWorldFactory, normalized_username: \Closure, applied_window: null, applied_admitted: bool, error: null}
     */
    public function definition(): array
    {
        return [
            'luanti_account_id' => LuantiAccount::factory(),
            'luanti_world_id' => LuantiWorld::factory(),
            'normalized_username' => fn (array $attributes): string => LuantiAccount::query()->findOrFail($attributes['luanti_account_id'])->normalized_username,
            'applied_window' => null,
            'applied_admitted' => false,
            'error' => null,
        ];
    }
}
