<?php

namespace App\Services;

use App\Jobs\CleanupLuantiDns;
use App\Jobs\ProvisionLuantiBridge;
use App\Models\LuantiServer;
use App\Models\User;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;
use Illuminate\Validation\ValidationException;

class HetznerVirtualMachines
{
    public const string NameHelp = '2–253 Zeichen insgesamt, höchstens 63 je Abschnitt zwischen Punkten. Erlaubt: Buchstaben, Ziffern, Bindestriche und Punkte. Jeder Abschnitt beginnt und endet mit Buchstabe oder Ziffer. Keine Leerzeichen; im Projekt eindeutig. Beispiel: test-2.0.';

    public function __construct(private PortalAccess $access, private HetznerCloudClient $http, private HetznerProvisioningKey $provisioningKey,
        private PortalSettings $settings, private LuantiDockerBootstrap $bootstrap) {}

    public static function generatedName(): string
    {
        $adjectives = ['amber', 'bright', 'calm', 'clear', 'coral', 'crisp', 'dawn', 'deep', 'fair', 'fresh', 'gentle', 'golden',
            'green', 'happy', 'hazy', 'keen', 'lively', 'lucky', 'mellow', 'misty', 'nimble', 'quiet', 'rapid', 'silver', 'soft', 'still', 'sunny', 'swift', 'vivid', 'warm'];
        $nouns = ['badger', 'birch', 'brook', 'cedar', 'comet', 'crane', 'deer', 'dove', 'elm', 'falcon', 'fern', 'finch',
            'fox', 'grove', 'heron', 'hill', 'lake', 'lark', 'lynx', 'maple', 'moss', 'otter', 'owl', 'pine', 'pond', 'reed', 'robin', 'spruce', 'vale', 'wren'];
        for ($attempt = 0; $attempt < 100; $attempt++) {
            $name = $adjectives[random_int(0, count($adjectives) - 1)].'-'.$nouns[random_int(0, count($nouns) - 1)];
            if (! LuantiServer::query()->where('hetzner_name', $name)->exists()) {
                return $name;
            }
        }

        throw ValidationException::withMessages(['name' => 'Kein freier Vorschlag gefunden. Bitte einen VM-Namen eingeben.']);
    }

    /** @return list<string|Unique> */
    public static function nameRules(): array
    {
        return ['required', 'string', 'min:2', 'max:253',
            'regex:/\A[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*\z/i',
            Rule::unique('luanti_servers', 'hetzner_name')];
    }

    /** @return array<string, string> */
    public static function nameValidationMessages(): array
    {
        return [
            'required' => 'Bitte einen VM-Namen eingeben.',
            'min' => 'Der VM-Name muss mindestens 2 Zeichen enthalten.',
            'max' => 'Der VM-Name darf insgesamt höchstens 253 Zeichen enthalten.',
            'regex' => 'Ungültiger VM-Name: Buchstaben, Ziffern, Bindestriche und Punkte verwenden; Abschnitte mit 1–63 Zeichen beginnen und enden mit Buchstabe oder Ziffer.',
            'unique' => 'Für diesen VM-Namen besteht bereits ein Portaleintrag. Bitte dessen Status aktualisieren oder einen anderen Namen wählen.',
        ];
    }

    /** @param array{name?: mixed, server_type?: mixed, location?: mixed, image?: mixed} $data */
    public function create(User $actor, array $data): LuantiServer
    {
        Gate::forUser($this->access->currentUser($actor))->authorize('create', LuantiServer::class);
        $this->http->assertConfigured();
        foreach (['name', 'server_type', 'location', 'image'] as $field) {
            if (is_string($data[$field] ?? null)) {
                $data[$field] = trim($data[$field]);
            }
        }
        if (is_string($data['name'] ?? null)) {
            $data['name'] = strtolower($data['name']);
        }
        $data = Validator::make($data, [
            'name' => self::nameRules(),
            'server_type' => ['required', 'string', 'max:63', 'regex:/\A[a-z0-9-]+\z/'],
            'location' => ['required', 'string', 'max:63', 'regex:/\A[a-z0-9-]+\z/'],
            'image' => ['required', 'string', 'max:63', 'regex:/\A[a-z0-9.-]+\z/'],
        ], collect(self::nameValidationMessages())->mapWithKeys(fn (string $message, string $rule): array => ['name.'.$rule => $message])->all())->validate();
        $luantiImage = $this->settings->luantiDockerImage();
        if ($luantiImage !== null && $data['image'] !== 'ubuntu-24.04') {
            throw ValidationException::withMessages(['image' => 'Die automatische Luanti-Installation benötigt derzeit Ubuntu 24.04.']);
        }
        $bridge = app(BridgeProvisioning::class)->prepare($actor);

        return $this->withLock('create:'.$data['name'], function () use ($actor, $data, $luantiImage, $bridge): LuantiServer {
            Gate::forUser($this->access->currentUser($actor))->authorize('create', LuantiServer::class);
            if (LuantiServer::query()->where('hetzner_name', $data['name'])->exists()) {
                throw ValidationException::withMessages(['name' => 'Für diesen VM-Namen besteht bereits ein Eintrag. Bitte dessen Status aktualisieren.']);
            }
            $https = app(HetznerDns::class)->prepare($data['name']);
            $server = new LuantiServer(['name' => $data['name'], 'game_port' => 30000]);
            $server->forceFill([
                'public_hostname' => $https === null ? null : $https['label'].'.'.$https['zone_name'],
                'domain' => $https === null ? null : $https['label'].'.'.$https['zone_name'],
                'public_https_configuration' => $https, 'dns_status' => $https === null ? null : 'pending',
                'https_status' => $https === null ? null : 'pending',
                'hetzner_reference' => (string) Str::uuid(), 'hetzner_name' => $data['name'], 'hetzner_status' => 'key_pending',
                'hetzner_create_payload' => $data,
                'luanti_docker_image' => $luantiImage,
                'bridge_configuration' => $bridge['configuration'] ?? null, 'bridge_commit' => $bridge['commit'] ?? null,
                'bridge_status' => $bridge === null ? null : 'pending', 'bridge_requested_by' => $bridge === null ? null : $actor->id,
            ])->save();

            return $this->withLock((string) $server->id, fn (): LuantiServer => $this->submitVm($actor, $server));
        });
    }

    public function retryKeySetup(User $actor, LuantiServer $server): LuantiServer
    {
        $this->authorize($actor, $server, 'update');

        return $this->withLock((string) $server->id, function () use ($actor, $server): LuantiServer {
            $server = $server->fresh();
            $this->authorize($actor, $server, 'update');
            if ($server->hetzner_status !== 'key_pending') {
                throw ValidationException::withMessages(['hetzner' => 'Die VM-Erstellung wurde bereits angefragt. Bitte den Status aktualisieren.']);
            }

            return $this->submitVm($actor, $server);
        });
    }

    private function submitVm(User $actor, LuantiServer $server): LuantiServer
    {
        if ($server->public_https_configuration !== null) {
            $configuration = $server->public_https_configuration;
            $dns = app(HetznerDns::class);
            $zone = $dns->zone($configuration['zone_id'], $configuration['zone_name']);
            $dns->assertAvailable($zone, $configuration['label']);
        }
        try {
            $this->bootstrap->prepare($server);
            $sshKeyId = $this->provisioningKey->ensureRegistered($server);
        } catch (ValidationException $exception) {
            return $this->saveError($server, $exception->errors()['hetzner'][0]);
        }
        $this->authorize($actor, $server, 'update');
        $server->forceFill(['hetzner_status' => 'creating', 'hetzner_error' => null])->save();
        try {
            $response = $this->http->request('POST', 'servers', [
                ...$server->hetzner_create_payload, 'ssh_keys' => [$sshKeyId],
                'start_after_create' => true, 'public_net' => ['enable_ipv4' => true, 'enable_ipv6' => true],
                'labels' => ['managed-by' => 'luanti-portal', 'portal-reference' => $server->hetzner_reference],
                ...($server->provisioning_cloud_config === null ? [] : ['user_data' => $server->provisioning_cloud_config]),
            ]);
        } catch (ConnectionException) {
            return $this->saveError($server, 'Ergebnis der Erstellung unbekannt. Bitte Status aktualisieren, bevor eine weitere VM angelegt wird.', 'unknown');
        }
        if ($response->status() !== 201) {
            $rejected = in_array($response->status(), [400, 401, 403, 404, 409, 412, 422, 423, 429], true);

            return $this->saveError($server, $this->apiError($response), $rejected ? 'create_failed' : 'unknown');
        }
        if (! $this->isOwnedServer($server, $response->json('server'))) {
            return $this->saveError($server, 'Ergebnis der Erstellung unbekannt. Bitte Status aktualisieren.', 'unknown');
        }

        $server = $this->saveRemote($server, $response->json('server'));
        if ($server->bridge_status === 'pending') {
            ProvisionLuantiBridge::dispatch($server->id)->afterCommit()->delay(now()->addSeconds(30));
        }

        return $server;
    }

    public function refresh(User $actor, LuantiServer $server): LuantiServer
    {
        $this->authorize($actor, $server, 'update');

        return $this->withLock((string) $server->id, function () use ($actor, $server): LuantiServer {
            $server = $server->fresh();
            $this->authorize($actor, $server, 'update');
            if (in_array($server->hetzner_status, ['deleted', 'create_failed', 'key_pending'], true)) {
                return $server;
            }
            $remote = $this->findRemote($server);

            return $remote === null ? $this->saveMissing($server) : $this->saveRemote($server, $remote);
        });
    }

    public function delete(User $actor, LuantiServer $server): LuantiServer
    {
        $this->authorize($actor, $server, 'delete');

        return $this->withLock((string) $server->id, function () use ($actor, $server): LuantiServer {
            return DB::transaction(function () use ($actor, $server): LuantiServer {
                $server = LuantiServer::query()->whereKey($server->id)->lockForUpdate()->firstOrFail();
                $this->authorize($actor, $server, 'delete');
                if ($server->hetzner_status === 'deleted') {
                    return $server;
                }
                if ($server->templates()->exists() || $server->gameInstances()->exists()) {
                    throw ValidationException::withMessages(['hetzner' => 'Diese VM enthält Weltvorlagen oder Spielinstanzen und kann nicht gelöscht werden.']);
                }
                if (in_array($server->hetzner_status, ['key_pending', 'create_failed'], true)) {
                    return $this->finishDeletion($server);
                }
                $remote = $this->findRemote($server);
                if ($remote === null) {
                    return $this->saveMissing($server);
                }
                $this->saveRemote($server, $remote);
                $this->authorize($actor, $server, 'delete');
                try {
                    $response = $this->http->request('DELETE', 'servers/'.$server->hetzner_server_id);
                } catch (ConnectionException) {
                    return $this->saveError($server, 'Ergebnis der Löschung unbekannt. Bitte Status aktualisieren.');
                }
                if ($response->status() === 404) {
                    return $this->saveMissing($server);
                }
                if (! $response->successful()) {
                    return $this->saveError($server, $this->apiError($response));
                }
                $server->forceFill(['hetzner_status' => 'deleting', 'hetzner_error' => null])->save();

                return $server;
            });
        });
    }

    private function authorize(User $actor, LuantiServer $server, string $ability): void
    {
        Gate::forUser($this->access->currentUser($actor))->authorize($ability, $server);
        if ($server->hetzner_reference === null) {
            throw ValidationException::withMessages(['hetzner' => 'Dieser Server wird nicht über Hetzner verwaltet.']);
        }
    }

    /** @return array<string, mixed>|null */
    private function findRemote(LuantiServer $server): ?array
    {
        try {
            $response = $server->hetzner_server_id !== null
                ? $this->http->request('GET', 'servers/'.$server->hetzner_server_id)
                : $this->http->request('GET', 'servers', ['name' => $server->hetzner_name, 'per_page' => 2]);
        } catch (ConnectionException) {
            throw ValidationException::withMessages(['hetzner' => 'Hetzner ist nicht erreichbar. Bitte später den Status aktualisieren.']);
        }
        if ($server->hetzner_server_id !== null && $response->status() === 404) {
            return null;
        }
        if (! $response->ok()) {
            throw ValidationException::withMessages(['hetzner' => $this->apiError($response)]);
        }
        if ($server->hetzner_server_id === null) {
            $servers = $response->json('servers');
            if ($servers === []) {
                return null;
            }
            $remote = is_array($servers) && count($servers) === 1 ? $servers[0] : null;
        } else {
            $remote = $response->json('server');
        }
        if (! $this->isOwnedServer($server, $remote)) {
            throw ValidationException::withMessages(['hetzner' => 'Die VM lässt sich diesem Portaleintrag nicht eindeutig zuordnen. Bitte im Hetzner-Projekt prüfen.']);
        }

        return $remote;
    }

    private function isOwnedServer(LuantiServer $server, mixed $remote): bool
    {
        return is_array($remote) && is_int($remote['id'] ?? null) && $remote['id'] > 0
            && ($server->hetzner_server_id === null || $server->hetzner_server_id === $remote['id'])
            && data_get($remote, 'labels.managed-by') === 'luanti-portal'
            && data_get($remote, 'labels.portal-reference') === $server->hetzner_reference
            && is_string($remote['status'] ?? null);
    }

    /** @param array<string, mixed> $remote */
    private function saveRemote(LuantiServer $server, array $remote): LuantiServer
    {
        $server->forceFill([
            'hetzner_server_id' => $remote['id'],
            'hetzner_status' => in_array($remote['status'], ['running', 'initializing', 'off', 'starting', 'stopping', 'deleting', 'migrating', 'rebuilding', 'unknown'], true) ? $remote['status'] : 'unknown',
            'hetzner_ipv4' => filter_var(data_get($remote, 'public_net.ipv4.ip'), FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ?: null,
            'hetzner_ipv6' => $this->ipv6Network(data_get($remote, 'public_net.ipv6.ip')),
            'hetzner_error' => null,
        ])->save();

        return $server;
    }

    private function ipv6Network(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $parts = explode('/', $value);

        return count($parts) === 2 && $parts[1] === '64' && filter_var($parts[0], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? $value : null;
    }

    private function saveMissing(LuantiServer $server): LuantiServer
    {
        if ($server->hetzner_server_id === null) {
            return $this->saveError($server, 'Noch keine VM gefunden. Bitte im Hetzner-Projekt prüfen und den Status später erneut aktualisieren.', 'unknown');
        }

        return $this->finishDeletion($server);
    }

    private function finishDeletion(LuantiServer $server): LuantiServer
    {
        $this->provisioningKey->removeRegisteredKey($server);
        $server->forceFill([
            'dns_cleanup_pending' => $server->public_https_configuration !== null && $server->dns_status !== 'deleted',
            'https_status' => $server->public_https_configuration === null ? null : 'deleted',
            'hetzner_status' => 'deleted', 'hetzner_ipv4' => null, 'hetzner_ipv6' => null, 'hetzner_error' => null,
            'api_token' => null, 'api_base_url' => null,
            'provisioning_private_key' => null, 'provisioning_public_key' => null,
            'hetzner_ssh_key_id' => null, 'hetzner_create_payload' => null,
            'provisioning_cloud_config' => null, 'provisioning_host_public_key' => null,
            'luanti_installation_status' => $server->luanti_docker_image === null ? null : 'deleted', 'luanti_verified_at' => null,
            'bridge_status' => $server->bridge_status === null ? null : 'deleted', 'bridge_ca_certificate' => null,
        ])->save();
        if ($server->dns_cleanup_pending) {
            CleanupLuantiDns::dispatch($server->id)->afterCommit();
        }

        return $server;
    }

    private function saveError(LuantiServer $server, string $message, ?string $status = null): LuantiServer
    {
        $server->forceFill(['hetzner_error' => $message, 'hetzner_status' => $status ?? $server->hetzner_status])->save();

        return $server;
    }

    private function apiError(Response $response): string
    {
        return match ($response->status()) {
            401, 403 => 'Hetzner hat den API-Zugang abgelehnt. Bitte einen Read-&-Write-Token des richtigen Projekts verwenden.',
            409 => 'Der VM-Name ist im Hetzner-Projekt bereits vergeben.',
            423 => 'Die VM ist gesperrt oder löschgeschützt. Bitte im Hetzner-Projekt prüfen.',
            429 => 'Hetzner begrenzt gerade die Anfragen. Bitte später erneut versuchen.',
            default => 'Hetzner konnte die Anfrage nicht bestätigen (HTTP '.$response->status().'). Bitte Konfiguration und VM-Status im Projekt prüfen.',
        };
    }

    /** @param Closure(): LuantiServer $operation */
    private function withLock(string $key, Closure $operation): LuantiServer
    {
        $result = Cache::lock('hetzner:vm:'.$key, 120)->get($operation);
        if (! $result instanceof LuantiServer) {
            throw ValidationException::withMessages(['hetzner' => 'Für diese VM läuft bereits eine Anfrage. Bitte kurz warten.']);
        }

        return $result;
    }
}
