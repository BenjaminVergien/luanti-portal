<?php

namespace Database\Seeders;

use App\Models\LuantiWorldTemplate;
use App\Models\OrganizationMembership;
use App\Services\WorldRequests;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Seeder;
use RuntimeException;

class WorldRequestSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local')) {
            throw new RuntimeException('Example world requests may only be created locally.');
        }
        $this->call(LuantiWorldTemplateSeeder::class);
        $world = LuantiWorldTemplate::query()->where('template_identifier', 'beispielwelt')
            ->whereHas('server', fn (Builder $query): Builder => $query->where('name', 'Luanti-Beispielserver'))->sole();
        if (! $world->is_available || ! $world->is_selectable || $world->revision === null) {
            return;
        }
        $membership = OrganizationMembership::query()->with(['organization', 'user'])
            ->whereHas('user', fn (Builder $query): Builder => $query->where('is_active', true)->whereNotNull('email_verified_at'))->first();
        if ($membership !== null && ! $membership->organization->worldRequests()->where('luanti_world_template_id', $world->id)->exists()) {
            app(WorldRequests::class)->create($membership->user, $membership->organization, [
                'name' => 'Beispiel-Weltantrag', 'luanti_world_template_id' => $world->id,
                'start_date' => now('Europe/Berlin')->toDateString(), 'end_date' => now('Europe/Berlin')->addDays(7)->toDateString(),
                'participant_count' => 12,
            ]);
        }
    }
}
