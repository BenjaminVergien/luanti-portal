<?php

namespace App\Services;

use App\Models\LuantiServer;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
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

    /** @return array{public_https_enabled: bool, dns_zone: string, acme_email: ?string} */
    public function publicHttps(): array
    {
        $row = DB::table('portal_settings')->where('id', 1)->first();

        return ['public_https_enabled' => (bool) ($row?->public_https_enabled ?? false),
            'dns_zone' => $row?->dns_zone ?? 'bensfoundry.de', 'acme_email' => $row?->acme_email];
    }

    public function dnsToken(): ?string
    {
        $token = DB::table('portal_settings')->where('id', 1)->value('dns_api_token');

        return $token === null ? null : Crypt::decryptString($token);
    }

    public function removeDnsToken(User $actor): void
    {
        Gate::forUser($this->access->currentUser($actor))->authorize('viewAny', LuantiServer::class);
        DB::table('portal_settings')->where('id', 1)->update(['dns_api_token' => null, 'public_https_enabled' => false, 'updated_at' => now()]);
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
            foreach (['dns_zone', 'acme_email', 'dns_api_token'] as $field) {
                if (is_string($data[$field] ?? null)) {
                    $data[$field] = trim($data[$field]);
                }
            }
            if (isset($data['dns_zone'])) {
                $data['dns_zone'] = strtolower($data['dns_zone']);
            }
            $images = array_keys($this->catalog->images());
            if ($this->luantiDockerImage() !== null) {
                $images[] = $this->luantiDockerImage();
            }
            $attributes = Validator::make($data, [
                'public_https_enabled' => ['sometimes', 'boolean'],
                'dns_zone' => ['sometimes', 'required', ...LuantiServerRegistry::domainRules()],
                'dns_api_token' => ['sometimes', 'nullable', 'string', 'max:256', 'not_regex:/\s/'],
                'acme_email' => ['sometimes', 'nullable', 'string', 'email:rfc', 'max:254', 'not_regex:/[\r\n\x00]/'],
                'bridge_enabled' => ['sometimes', 'boolean'],
                'bridge_repository' => ['sometimes', 'required', 'string', 'max:200', 'regex:/\A[A-Za-z0-9][A-Za-z0-9-]{0,38}\/[A-Za-z0-9_.-]{1,100}\z/', 'not_in:.,..'],
                'bridge_ref' => ['sometimes', 'required', 'string', 'max:200', 'regex:/\A[A-Za-z0-9][A-Za-z0-9_.\/-]*\z/', 'not_regex:/\.\./'],
                'bridge_github_token' => ['sometimes', 'nullable', 'string', 'max:512', 'not_regex:/\s/'],
                'bridge_game_source' => ['sometimes', 'nullable', 'string', 'max:2048', 'not_regex:/[\r\n\x00]/'],
                'bridge_admin_name' => ['sometimes', 'required', 'string', 'regex:/\A[A-Za-z0-9_-]{1,20}\z/'],
                'luanti_docker_image' => ['sometimes', 'nullable', 'string', 'max:255',
                    'regex:/\Aghcr\.io\/luanti-org\/luanti:[A-Za-z0-9_][A-Za-z0-9_.-]{0,127}\z/', Rule::in($images)],
            ], ['luanti_docker_image.in' => 'Bitte ein Image aus der geladenen Liste auswählen oder das gespeicherte Image beibehalten.'])->validate();
            $effective = array_replace($this->publicHttps(), $this->bridge(), ['luanti_docker_image' => $this->luantiDockerImage()], $attributes);
            if ($effective['public_https_enabled']) {
                if (! $effective['bridge_enabled'] || blank($effective['luanti_docker_image'])) {
                    throw ValidationException::withMessages(['public_https_enabled' => 'Öffentliches HTTPS benötigt die automatische Bridge-Installation und ein Luanti-Image.']);
                }
                if (blank($effective['acme_email'])) {
                    throw ValidationException::withMessages(['acme_email' => 'Bitte eine E-Mail-Adresse für das Zertifikat hinterlegen.']);
                }
                if (blank($attributes['dns_api_token'] ?? null) && blank($this->dnsToken())) {
                    throw ValidationException::withMessages(['dns_api_token' => 'Bitte einen Read-&-Write-Token aus dem DNS-Projekt KonsoleH hinterlegen.']);
                }
            }
            if (array_key_exists('dns_api_token', $attributes)) {
                if (filled($attributes['dns_api_token'])) {
                    $attributes['dns_api_token'] = Crypt::encryptString($attributes['dns_api_token']);
                } else {
                    unset($attributes['dns_api_token']);
                }
            }
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
