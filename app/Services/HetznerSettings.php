<?php

namespace App\Services;

use App\Models\LuantiServer;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use SensitiveParameter;

class HetznerSettings
{
    public function __construct(private PortalAccess $access) {}

    public function token(): ?string
    {
        $settings = DB::table('hetzner_settings')->where('id', 1)->first();
        if ($settings !== null) {
            return $settings->api_token === null ? null : Crypt::decryptString($settings->api_token);
        }

        return config('services.hetzner.token');
    }

    public function hasToken(): bool
    {
        return filled($this->token());
    }

    /** @param array{api_token?: mixed} $data */
    public function save(User $actor, #[SensitiveParameter] array $data): void
    {
        $this->authorize($actor);
        $token = $data['api_token'] ?? null;
        if (is_string($token)) {
            $token = trim($token);
        }
        $attributes = Validator::make(['api_token' => $token], [
            'api_token' => [Rule::requiredIf(! $this->hasToken()), 'nullable', 'string', 'max:256', 'not_regex:/\s/'],
        ])->validate();
        if (blank($attributes['api_token'])) {
            return;
        }

        DB::table('hetzner_settings')->updateOrInsert(['id' => 1], [
            'api_token' => Crypt::encryptString($attributes['api_token']), 'updated_at' => now(), 'created_at' => now(),
        ]);
    }

    public function removeToken(User $actor): void
    {
        $this->authorize($actor);
        DB::table('hetzner_settings')->updateOrInsert(['id' => 1], ['api_token' => null, 'updated_at' => now(), 'created_at' => now()]);
    }

    private function authorize(User $actor): void
    {
        Gate::forUser($this->access->currentUser($actor))->authorize('viewAny', LuantiServer::class);
    }
}
