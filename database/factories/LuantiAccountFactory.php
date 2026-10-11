<?php

namespace Database\Factories;

use App\Models\LuantiAccount;
use App\Models\WorldRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LuantiAccount>
 */
class LuantiAccountFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array{world_request_id: WorldRequestFactory, username: string, normalized_username: string, role: string, initial_password: string}
     */
    public function definition(): array
    {
        return [
            'world_request_id' => WorldRequest::factory()->approved(),
            'username' => $username = 'player_'.fake()->unique()->numerify('########'),
            'normalized_username' => strtolower($username),
            'role' => 'participant',
            'initial_password' => fake()->password(20, 24),
        ];
    }
}
