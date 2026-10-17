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

class PortalSettings
{
    public function __construct(private PortalAccess $access, private HetznerSettings $hetzner, private LuantiImageCatalog $catalog) {}

    public function luantiDockerImage(): ?string
    {
        return DB::table('portal_settings')->where('id', 1)->value('luanti_docker_image');
    }

    /** @return array{bridge_enabled: bool, bridge_repository: string, bridge_ref: string, bridge_game_source: ?string, bridge_admin_name: string} */
    public function bridge(): array
    {
        $row = DB::table('portal_settings')->where('id', 1)->first();

        return ['bridge_enabled' => (bool) ($row?->bridge_enabled ?? false),
            'bridge_repository' => $row?->bridge_repository ?? 'Stewie23/luanti-connect',
            'bridge_ref' => $row?->bridge_ref ?? 'main',
            'bridge_game_source' => $row?->bridge_game_source ?? config('services.bridge_development.game_source'),
            'bridge_admin_name' => $row?->bridge_admin_name ?? 'Administrator'];
    }

    public function githubToken(): ?string
    {
        $token = DB::table('portal_settings')->where('id', 1)->value('bridge_github_token');

        return $token === null ? null : Crypt::decryptString($token);
    }

    public function removeGithubToken(User $actor): void
    {
        Gate::forUser($this->access->currentUser($actor))->authorize('viewAny', LuantiServer::class);
        DB::table('portal_settings')->where('id', 1)->update(['bridge_github_token' => null, 'updated_at' => now()]);
    }

    /** @param array<string, mixed> $data */
    public function save(User $actor, #[SensitiveParameter] array $data): void
    {
        DB::transaction(function () use ($actor, $data): void {
            Gate::forUser($this->access->currentUser($actor))->authorize('viewAny', LuantiServer::class);
            $images = array_keys($this->catalog->images());
            if ($this->luantiDockerImage() !== null) {
                $images[] = $this->luantiDockerImage();
            }
            $attributes = Validator::make($data, [
                'bridge_enabled' => ['sometimes', 'boolean'],
                'bridge_repository' => ['sometimes', 'required', 'string', 'max:200', 'regex:/\A[A-Za-z0-9][A-Za-z0-9-]{0,38}\/[A-Za-z0-9_.-]{1,100}\z/', 'not_in:.,..'],
                'bridge_ref' => ['sometimes', 'required', 'string', 'max:200', 'regex:/\A[A-Za-z0-9][A-Za-z0-9_.\/-]*\z/', 'not_regex:/\.\./'],
                'bridge_github_token' => ['sometimes', 'nullable', 'string', 'max:512', 'not_regex:/\s/'],
                'bridge_game_source' => ['sometimes', 'nullable', 'string', 'max:2048', 'not_regex:/[\r\n\x00]/'],
                'bridge_admin_name' => ['sometimes', 'required', 'string', 'regex:/\A[A-Za-z0-9_-]{1,20}\z/'],
                'luanti_docker_image' => ['sometimes', 'nullable', 'string', 'max:255',
                    'regex:/\Aghcr\.io\/luanti-org\/luanti:[A-Za-z0-9_][A-Za-z0-9_.-]{0,127}\z/', Rule::in($images)],
            ], ['luanti_docker_image.in' => 'Bitte ein Image aus der geladenen Liste auswählen oder das gespeicherte Image beibehalten.'])->validate();
            if (array_key_exists('bridge_github_token', $attributes)) {
                if (filled($attributes['bridge_github_token'])) {
                    $attributes['bridge_github_token'] = Crypt::encryptString($attributes['bridge_github_token']);
                } else {
                    unset($attributes['bridge_github_token']);
                }
            }
            if (filled($data['api_token'] ?? null)) {
                $this->hetzner->save($actor, $data);
            }
            if ($attributes !== []) {
                DB::table('portal_settings')->updateOrInsert(['id' => 1], [
                    ...$attributes, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        });
    }
}
