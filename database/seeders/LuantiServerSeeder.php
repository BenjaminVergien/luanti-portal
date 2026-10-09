<?php

namespace Database\Seeders;

use App\Models\LuantiServer;
use Illuminate\Database\Seeder;
use RuntimeException;

class LuantiServerSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local')) {
            throw new RuntimeException('Example Luanti servers may only be created locally.');
        }

        LuantiServer::query()->firstOrCreate(
            ['name' => 'Luanti-Beispielserver'],
            ['domain' => 'luanti.example.test', 'game_port' => 30000, 'notes' => 'Fiktiver Server für die lokale Entwicklung.'],
        );
    }
}
