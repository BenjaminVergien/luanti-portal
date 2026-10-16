<?php

namespace App\Services;

use App\Models\LuantiServer;
use App\Models\User;
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

    /** @param array{api_token?: mixed, luanti_docker_image?: mixed} $data */
    public function save(User $actor, #[SensitiveParameter] array $data): void
    {
        DB::transaction(function () use ($actor, $data): void {
            Gate::forUser($this->access->currentUser($actor))->authorize('viewAny', LuantiServer::class);
            $images = array_keys($this->catalog->images());
            if ($this->luantiDockerImage() !== null) {
                $images[] = $this->luantiDockerImage();
            }
            $attributes = Validator::make($data, [
                'luanti_docker_image' => ['sometimes', 'nullable', 'string', 'max:255',
                    'regex:/\Aghcr\.io\/luanti-org\/luanti:[A-Za-z0-9_][A-Za-z0-9_.-]{0,127}\z/', Rule::in($images)],
            ], ['luanti_docker_image.in' => 'Bitte ein Image aus der geladenen Liste auswählen oder das gespeicherte Image beibehalten.'])->validate();
            if (filled($data['api_token'] ?? null)) {
                $this->hetzner->save($actor, $data);
            }
            if (array_key_exists('luanti_docker_image', $attributes)) {
                DB::table('portal_settings')->updateOrInsert(['id' => 1], [
                    'luanti_docker_image' => $attributes['luanti_docker_image'], 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        });
    }
}
