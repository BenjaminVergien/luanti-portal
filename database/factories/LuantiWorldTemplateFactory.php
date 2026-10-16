<?php

namespace Database\Factories;

use App\Models\LuantiServer;
use App\Models\LuantiWorldTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<LuantiWorldTemplate> */
class LuantiWorldTemplateFactory extends Factory
{
    /** @return array{luanti_server_id: LuantiServerFactory, name: string, template_identifier: string, is_selectable: bool} */
    public function definition(): array
    {
        return ['luanti_server_id' => LuantiServer::factory(), 'name' => fake()->words(3, true),
            'template_identifier' => fake()->unique()->slug(), 'revision' => str_repeat('a', 64), 'is_selectable' => true, 'is_available' => true];
    }

    public function retired(): static
    {
        return $this->state(fn (): array => ['is_selectable' => false]);
    }
}
