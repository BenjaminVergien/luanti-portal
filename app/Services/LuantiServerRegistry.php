<?php

namespace App\Services;

use App\Models\LuantiServer;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

class LuantiServerRegistry
{
    public function __construct(private PortalAccess $access) {}

    /**
     * Validate a DNS hostname without performing a DNS lookup.
     *
     * @return list<string>
     */
    public static function domainRules(): array
    {
        return ['required', 'string', 'max:253', 'regex:/\\A(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\\.)+[a-z](?:[a-z0-9-]{0,61}[a-z0-9])?\\z/i'];
    }

    /** @return Builder<LuantiServer> */
    public function query(User $actor): Builder
    {
        Gate::forUser($this->access->currentUser($actor))->authorize('viewAny', LuantiServer::class);

        return LuantiServer::query();
    }

    /** @param array{name?: mixed, notes?: mixed, domain?: mixed, game_port?: mixed, api_base_url?: mixed, api_token?: mixed} $data */
    public function create(User $actor, #[SensitiveParameter] array $data): LuantiServer
    {
        Gate::forUser($this->access->currentUser($actor))->authorize('create', LuantiServer::class);

        return LuantiServer::query()->create($this->validate($data));
    }

    /** @param array{name?: mixed, notes?: mixed, domain?: mixed, game_port?: mixed, api_base_url?: mixed, api_token?: mixed} $data */
    public function update(User $actor, LuantiServer $server, #[SensitiveParameter] array $data): void
    {
        Gate::forUser($this->access->currentUser($actor))->authorize('update', $server);
        $attributes = $this->validate($data);
        if ($attributes['api_token'] === null) {
            unset($attributes['api_token']);
        }

        $server->update($attributes);
    }

    public function removeToken(User $actor, LuantiServer $server): void
    {
        Gate::forUser($this->access->currentUser($actor))->authorize('update', $server);
        $server->update(['api_token' => null]);
    }

    public function delete(User $actor, LuantiServer $server): void
    {
        DB::transaction(function () use ($actor, $server): void {
            $server = LuantiServer::query()->whereKey($server->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($this->access->currentUser($actor))->authorize('delete', $server);
            if ($server->templates()->exists()) {
                throw ValidationException::withMessages(['server' => 'Dieser Server enthält Katalogwelten und kann nicht gelöscht werden.']);
            }
            $server->delete();
        });
    }

    /**
     * @param  array{name?: mixed, notes?: mixed, domain?: mixed, game_port?: mixed, api_base_url?: mixed, api_token?: mixed}  $data
     * @return array{name: string, notes: ?string, domain: string, game_port: int|string, api_base_url: ?string, api_token: ?string}
     */
    private function validate(#[SensitiveParameter] array $data): array
    {
        foreach (['name', 'notes', 'domain', 'api_base_url'] as $field) {
            if (is_string($data[$field] ?? null)) {
                $data[$field] = trim($data[$field]);
            }
        }
        if (is_string($data['domain'] ?? null)) {
            $data['domain'] = strtolower($data['domain']);
        }
        foreach (['notes', 'api_base_url', 'api_token'] as $field) {
            if (! isset($data[$field]) || (is_string($data[$field]) && blank($data[$field]))) {
                $data[$field] = null;
            }
        }

        return Validator::make($data, [
            'name' => ['required', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
            'domain' => self::domainRules(),
            'game_port' => ['required', 'integer', 'between:1,65535'],
            'api_base_url' => ['nullable', 'string', 'max:2048', 'url:https'],
            'api_token' => ['nullable', 'string'],
        ], [], [
            'name' => 'Name', 'notes' => 'Notizen', 'domain' => 'Domain',
            'game_port' => 'Spiel-Port', 'api_base_url' => 'HTTPS-API-URL', 'api_token' => 'API-Token',
        ])->validate();
    }
}
