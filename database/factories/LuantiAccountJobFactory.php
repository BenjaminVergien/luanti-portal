<?php

namespace Database\Factories;

use App\Models\LuantiAccount;
use App\Models\LuantiAccountAssignment;
use App\Models\LuantiAccountJob;
use App\Models\LuantiWorld;
use App\Models\WorldRequest;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<LuantiAccountJob>
 */
class LuantiAccountJobFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array{world_request_id: WorldRequestFactory, luanti_world_id: \Closure, job_id: string, operation: string, state: string, generation: int, base_url: string, world_identifier: \Closure, account_ids: \Closure, request_payload: \Closure}
     */
    public function definition(): array
    {
        return [
            'world_request_id' => WorldRequest::factory()->approved(),
            'luanti_world_id' => fn (array $attributes): int => WorldRequest::query()->findOrFail($attributes['world_request_id'])->luanti_world_id,
            'job_id' => 'fixture-'.Str::uuid(),
            'operation' => 'grant',
            'state' => 'queued',
            'generation' => 1,
            'base_url' => 'https://bridge.example.test',
            'world_identifier' => fn (array $attributes): string => LuantiWorld::query()->findOrFail($attributes['luanti_world_id'])->world_identifier,
            'account_ids' => function (array $attributes): array {
                $account = LuantiAccount::factory()->create(['world_request_id' => $attributes['world_request_id']]);
                LuantiAccountAssignment::factory()->create(['luanti_account_id' => $account->id, 'luanti_world_id' => $attributes['luanti_world_id']]);

                return [$account->id];
            },
            'request_payload' => function (array $attributes): string {
                $request = WorldRequest::query()->findOrFail($attributes['world_request_id']);
                $window = ['starts_at' => CarbonImmutable::parse($request->start_date->toDateString(), 'Europe/Berlin')->timestamp,
                    'ends_at' => CarbonImmutable::parse($request->end_date->toDateString(), 'Europe/Berlin')->addDay()->timestamp];
                $accounts = LuantiAccount::query()->whereIn('id', $attributes['account_ids'])->get()->map(fn (LuantiAccount $account): array => [
                    'username' => $account->username, 'role' => $account->role, 'initial_password' => $account->initial_password,
                    'admitted' => true, 'access_window' => $window,
                ])->all();

                return json_encode(['version' => 2, 'job_id' => $attributes['job_id'], 'operation' => 'sync_accounts', 'accounts' => $accounts], JSON_THROW_ON_ERROR);
            },
        ];
    }
}
