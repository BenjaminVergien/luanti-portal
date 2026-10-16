<?php

namespace Database\Seeders;

use App\Models\LuantiServer;
use App\Models\LuantiWorldTemplate;
use Illuminate\Database\Seeder;
use RuntimeException;

class LuantiWorldTemplateSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local')) {
            throw new RuntimeException('Example Luanti worlds may only be created locally.');
        }
        $this->call(LuantiServerSeeder::class);
        $server = LuantiServer::query()->where('name', 'Luanti-Beispielserver')->sole();
        LuantiWorldTemplate::query()->firstOrCreate(['luanti_server_id' => $server->id, 'template_identifier' => 'beispielwelt'],
            ['name' => 'Luanti-Beispielvorlage (noch nicht veröffentlicht)', 'is_selectable' => false]);
    }
}
