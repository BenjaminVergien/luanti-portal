<?php

namespace Database\Factories;

use App\Models\LuantiServer;
use App\Models\LuantiWorld;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<LuantiWorld> */
class LuantiWorldFactory extends Factory
{
    /** @return array{luanti_server_id: LuantiServerFactory, name: string, world_identifier: string, is_selectable: bool} */
    public function definition(): array
    {
        return ['luanti_server_id' => LuantiServer::factory(), 'name' => fake()->words(3, true),
            'world_identifier' => fake()->unique()->slug(), 'is_selectable' => true];
    }

    public function retired(): static
    {
        return $this->state(fn (): array => ['is_selectable' => false]);
    }
}
