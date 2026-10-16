<?php

namespace Database\Factories;

use App\Models\LuantiServer;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<LuantiServer>
 */
class LuantiServerFactory extends Factory
{
    /** @return array{name: string, notes: null, domain: string, game_port: int, api_base_url: null, api_token: null} */
    public function definition(): array
    {
        return [
            'name' => fake()->words(3, true),
            'notes' => null,
            'domain' => 'server-'.fake()->numberBetween(1, 254).'.example.test',
            'game_port' => 30000,
            'api_base_url' => null,
            'api_token' => null,
        ];
    }

    public function hetznerVm(): static
    {
        return $this->state(fn (): array => [
            'hetzner_reference' => (string) Str::uuid(), 'hetzner_server_id' => 42,
            'hetzner_name' => 'voxelgarden-test', 'hetzner_status' => 'running',
        ]);
    }
}
