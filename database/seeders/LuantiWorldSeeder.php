<?php

namespace Database\Seeders;

use App\Models\LuantiServer;
use App\Models\LuantiWorld;
use Illuminate\Database\Seeder;
use RuntimeException;

class LuantiWorldSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local')) {
            throw new RuntimeException('Example Luanti worlds may only be created locally.');
        }
        $this->call(LuantiServerSeeder::class);
        $server = LuantiServer::query()->where('name', 'Luanti-Beispielserver')->sole();
        LuantiWorld::query()->firstOrCreate(['luanti_server_id' => $server->id, 'world_identifier' => 'beispielwelt'],
            ['name' => 'Luanti-Beispielwelt', 'is_selectable' => true]);
    }
}
