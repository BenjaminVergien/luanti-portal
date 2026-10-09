<?php

namespace Database\Factories;

use App\Models\LuantiServer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LuantiServer>
 */
class LuantiServerFactory extends Factory
{
    /** @return array{name: string, notes: null, ip_address: string, game_port: int, api_base_url: null, api_token: null} */
    public function definition(): array
    {
        return [
            'name' => fake()->words(3, true),
            'notes' => null,
            'ip_address' => '192.0.2.'.fake()->numberBetween(1, 254),
            'game_port' => 30000,
            'api_base_url' => null,
            'api_token' => null,
        ];
    }
}
