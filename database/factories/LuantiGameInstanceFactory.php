<?php

namespace Database\Factories;

use App\Models\LuantiGameInstance;
use App\Models\LuantiWorldTemplate;
use App\Models\WorldRequest;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<LuantiGameInstance>
 */
class LuantiGameInstanceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'world_request_id' => WorldRequest::factory(),
            'luanti_world_template_id' => fn (array $attributes): int => WorldRequest::findOrFail($attributes['world_request_id'])->luanti_world_template_id,
            'luanti_server_id' => fn (array $attributes): int => LuantiWorldTemplate::findOrFail($attributes['luanti_world_template_id'])->luanti_server_id,
            'instance_identifier' => 'g'.strtolower((string) Str::ulid()),
            'template_identifier' => fn (array $attributes): string => LuantiWorldTemplate::findOrFail($attributes['luanti_world_template_id'])->template_identifier,
            'template_revision' => str_repeat('a', 64), 'state' => 'preparing',
        ];
    }
}
