<?php

namespace App\Services;

use App\Models\LuantiServer;
use App\Models\LuantiWorld;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class LuantiWorldRegistry
{
    public function __construct(private PortalAccess $access) {}

    /** @return Builder<LuantiWorld> */
    public function query(User $actor): Builder
    {
        Gate::forUser($this->access->currentUser($actor))->authorize('viewAny', LuantiWorld::class);

        return LuantiWorld::query()->with('server');
    }

    /** @param array{luanti_server_id?: mixed, name?: mixed, world_identifier?: mixed, is_selectable?: mixed} $data */
    public function create(User $actor, array $data): LuantiWorld
    {
        Gate::forUser($this->access->currentUser($actor))->authorize('create', LuantiWorld::class);
        $attributes = $this->validate($data);

        return DB::transaction(function () use ($actor, $data, $attributes): LuantiWorld {
            LuantiServer::query()->whereKey($attributes['luanti_server_id'])->lockForUpdate()->firstOrFail();
            Gate::forUser($this->access->currentUser($actor))->authorize('create', LuantiWorld::class);
            $attributes = $this->validate($data);

            return LuantiWorld::query()->create($attributes);
        });
    }

    /** @param array{luanti_server_id?: mixed, name?: mixed, world_identifier?: mixed, is_selectable?: mixed} $data */
    public function update(User $actor, LuantiWorld $world, array $data): void
    {
        $world = $world->fresh();
        abort_if($world === null, 404);
        Gate::forUser($this->access->currentUser($actor))->authorize('update', $world);
        $attributes = $this->validate($data, $world);

        DB::transaction(function () use ($actor, $world, $data, $attributes): void {
            LuantiServer::query()->whereIn('id', [$world->luanti_server_id, $attributes['luanti_server_id']])
                ->orderBy('id')->lockForUpdate()->get();
            $actor = $this->access->currentUser($actor);
            $originalServerId = $world->luanti_server_id;
            $world = LuantiWorld::query()->whereKey($world->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('update', $world);
            if ($world->luanti_server_id !== $originalServerId) {
                throw ValidationException::withMessages(['luanti_server_id' => 'Der Server wurde inzwischen geändert. Bitte öffne das Formular erneut.']);
            }
            $attributes = $this->validate($data, $world);
            if (($world->last_discovered_at !== null || $world->worldRequests()->exists()) && ((int) $attributes['luanti_server_id'] !== $world->luanti_server_id
                || $attributes['world_identifier'] !== $world->world_identifier)) {
                throw ValidationException::withMessages(['world_identifier' => 'Server und Weltkennung eines synchronisierten oder bereits beantragten Eintrags können nicht geändert werden.']);
            }
            $world->update($attributes);
        });
    }

    /**
     * @param  array{luanti_server_id?: mixed, name?: mixed, world_identifier?: mixed, is_selectable?: mixed}  $data
     * @return array{luanti_server_id: int|string, name: string, world_identifier: string, is_selectable: bool|int|string}
     */
    private function validate(array $data, ?LuantiWorld $world = null): array
    {
        foreach (['name', 'world_identifier'] as $field) {
            if (is_string($data[$field] ?? null)) {
                $data[$field] = trim($data[$field]);
            }
        }

        return Validator::make($data, [
            'luanti_server_id' => ['required', 'integer', Rule::exists('luanti_servers', 'id')],
            'name' => ['required', 'string', 'max:255'],
            'world_identifier' => ['required', 'string', 'max:255', Rule::unique('luanti_worlds')
                ->where('luanti_server_id', $data['luanti_server_id'] ?? null)->ignore($world?->id)],
            'is_selectable' => ['required', 'boolean'],
        ], [], ['luanti_server_id' => 'Server', 'name' => 'Name', 'world_identifier' => 'Weltkennung', 'is_selectable' => 'Beantragbar'])->validate();
    }
}
