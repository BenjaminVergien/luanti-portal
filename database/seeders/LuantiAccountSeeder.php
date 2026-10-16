<?php

namespace Database\Seeders;

use App\Models\LuantiAccount;
use App\Models\LuantiAccountAssignment;
use Illuminate\Database\Seeder;

class LuantiAccountSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        if (! app()->environment('local')) {
            return;
        }
        $account = LuantiAccount::factory()->create();
        LuantiAccountAssignment::factory()->create(['luanti_account_id' => $account->id,
            'luanti_game_instance_id' => $account->worldRequest->current_game_instance_id]);
    }
}
