<?php

namespace App\Services;

use App\Jobs\ProvisionLuantiBridge;
use App\Models\LuantiServer;
use App\Models\User;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

class BridgeProvisioning
{
    public function __construct(private PortalSettings $settings, private GithubBridgeSource $source, private LuantiDockerInstallation $engine, private LuantiSshClient $ssh) {}

    /** @return array{configuration: array<string, mixed>, commit: string}|null */
    public function prepare(User $actor): ?array
    {
        Gate::forUser($actor)->authorize('create', LuantiServer::class);
        $settings = $this->settings->bridge();
        if (! $settings['bridge_enabled']) {
            return null;
        }
        if ($this->settings->luantiDockerImage() === null) {
            throw ValidationException::withMessages(['bridge' => 'Für die automatische Bridge-Installation bitte ein Luanti-Image auswählen.']);
        }
        $game = $this->source->game($settings['bridge_game_source'] ?? '');
        $commit = $this->source->resolve($settings['bridge_repository'], $settings['bridge_ref']);
        $bundle = $this->source->bundle($settings['bridge_repository'], $commit);
        $archive = $this->retain($bundle['bytes']);
        $gameHash = $this->retain($game);

        return ['commit' => $commit, 'configuration' => ['repository' => $settings['bridge_repository'], 'ref' => $settings['bridge_ref'],
            'admin_name' => $settings['bridge_admin_name'], 'source_sha256' => $bundle['source_sha256'], 'source_archive_sha256' => $archive, 'game_archive_sha256' => $gameHash]];
    }

    public function retry(User $actor, LuantiServer $server): void
    {
        Gate::forUser(app(PortalAccess::class)->currentUser($actor))->authorize('update', $server);
        Cache::lock('hetzner:vm:'.$server->id, 1260)->block(1, function () use ($actor, $server): void {
            $server = $server->fresh();
            if ($server->bridge_configuration === null || ! in_array($server->bridge_status, ['failed', 'pending', 'installing'], true)
                || in_array($server->hetzner_status, ['deleted', 'deleting', 'create_failed'], true)) {
                throw ValidationException::withMessages(['bridge' => 'Für diesen Server ist keine wiederholbare Bridge-Installation vorhanden.']);
            }
            $server->forceFill(['bridge_status' => 'pending', 'bridge_error' => null, 'bridge_requested_by' => $actor->id,
                'public_provisioning_attempts' => 0, 'public_provisioning_next_at' => null,
                'https_status' => $server->public_https_configuration === null ? null : 'pending', 'https_error' => null, 'dns_error' => null])->save();
            if ($server->dns_status === 'failed') {
                $server->forceFill(['dns_status' => 'pending'])->save();
            }
            app(UniqueLock::class)->release(new ProvisionLuantiBridge($server->id));
            ProvisionLuantiBridge::dispatch($server->id)->afterCommit();
        });
    }

    public function process(int $serverId): bool
    {
        $server = LuantiServer::query()->find($serverId);
        if ($server === null || ! in_array($server->bridge_status, ['pending', 'installing'], true)
            || in_array($server->hetzner_status, ['deleted', 'deleting', 'create_failed', 'key_pending'], true)) {
            return false;
        }
        try {
            $actor = User::query()->findOrFail($server->bridge_requested_by);
            Gate::forUser(app(PortalAccess::class)->currentUser($actor))->authorize('update', $server);
            if ($server->public_https_configuration !== null) {
                if ($server->public_provisioning_next_at?->isFuture()) {
                    return true;
                }
                $server = app(HetznerVirtualMachines::class)->refresh($actor, $server);
                $ready = Cache::lock('hetzner:vm:'.$serverId, 120)->block(1, function () use ($server, $actor): bool {
                    $server->refresh();
                    Gate::forUser(app(PortalAccess::class)->currentUser($actor))->authorize('update', $server);
                    if (! in_array($server->bridge_status, ['pending', 'installing'], true)
                        || in_array($server->hetzner_status, ['deleted', 'deleting', 'create_failed'], true)) {
                        return false;
                    }
                    if ($server->public_provisioning_attempts >= 12) {
                        throw ValidationException::withMessages(['https' => 'Automatische Versuche ausgeschöpft. Bitte die Einrichtung erneut anfragen.']);
                    }
                    $server->forceFill(['public_provisioning_attempts' => $server->public_provisioning_attempts + 1])->save();
                    if ($server->hetzner_status !== 'running') {
                        throw new PublicEndpointPendingException('Die VM ist noch nicht bereit.');
                    }
                    app(HetznerDns::class)->ensureRecord($server);

                    return true;
                });
                if (! $ready) {
                    return false;
                }
            }
            if ($server->luanti_installation_status !== 'verified') {
                $result = $this->engine->verify($actor, $server);
                $server->refresh();
                if (! $result['successful']) {
                    if ($server->public_https_configuration !== null && $server->luanti_installation_status !== 'failed') {
                        throw new PublicEndpointPendingException('Die Luanti-Installation ist noch nicht bereit.');
                    }
                    if ($server->luanti_installation_status !== 'failed' && $server->created_at->greaterThan(now()->subMinutes(30))) {
                        return true;
                    }
                    throw ValidationException::withMessages(['bridge' => 'Die Luanti-Installation ist nicht bereit. Bitte Installation prüfen und danach die Bridge-Installation erneut starten.']);
                }
            } elseif ($server->public_https_configuration === null) {
                $server = app(HetznerVirtualMachines::class)->refresh($actor, $server);
            }

            return Cache::lock('hetzner:vm:'.$serverId, 1260)->block(1, function () use ($server, $actor): bool {
                $server = $server->fresh();
                Gate::forUser(app(PortalAccess::class)->currentUser($actor))->authorize('update', $server);
                if ($server->hetzner_status !== 'running' || ! in_array($server->bridge_status, ['pending', 'installing'], true)) {
                    return false;
                }
                $public = $server->public_https_configuration !== null;
                $configuration = $server->bridge_configuration;
                if (! $public || $server->bridge_installed_at === null) {
                    $payload = ['reference' => $server->hetzner_reference, 'image_id' => $server->luanti_image_id, 'commit' => $server->bridge_commit,
                        'admin_name' => $configuration['admin_name'], 'source_sha256' => $configuration['source_sha256'],
                        'source_archive_sha256' => $configuration['source_archive_sha256'], 'game_archive_sha256' => $configuration['game_archive_sha256'],
                        'source' => base64_encode($this->artifact($configuration['source_archive_sha256'])),
                        'game' => base64_encode($this->artifact($configuration['game_archive_sha256']))];
                    if ($public) {
                        $payload['hostname'] = $server->public_hostname;
                    }
                    $server->forceFill(['bridge_status' => 'installing', 'bridge_error' => null])->save();
                    $script = 'PAYLOAD = __import__("json").loads(__import__("base64").b64decode("'.base64_encode(json_encode($payload, JSON_THROW_ON_ERROR)).'"))'."\n"
                        .file_get_contents(resource_path('provisioning/install_bridge.py'));
                    $response = json_decode($this->ssh->execute($server, $script, 900, 65536), true, flags: JSON_THROW_ON_ERROR);
                    if (($response['ok'] ?? false) !== true || ($response['commit'] ?? null) !== $server->bridge_commit
                        || ($response['source_sha256'] ?? null) !== $configuration['source_sha256'] || ($response['image_id'] ?? null) !== $server->luanti_image_id
                        || ! preg_match('/\A[A-Za-z0-9_-]{43}\z/', $response['token'] ?? '')
                        || (! $public && (! is_string($response['ca_certificate'] ?? null) || strlen($response['ca_certificate']) > 16384
                        || openssl_x509_read($response['ca_certificate']) === false))) {
                        throw ValidationException::withMessages(['bridge' => 'Die Bridge-Installation konnte nicht bestätigt werden. Ein erneuter Versuch verwendet denselben Commit und erhält vorhandene Daten.']);
                    }
                    Gate::forUser(app(PortalAccess::class)->currentUser($actor))->authorize('update', $server);
                    if ($public) {
                        $server->forceFill(['api_token' => $response['token'], 'bridge_installed_at' => now(),
                            'bridge_source_sha256' => $configuration['source_sha256'], 'public_provisioning_next_at' => now()->addSeconds(30)])->save();

                        return true;
                    }
                }
                if ($public) {
                    app(PublicHttpsProvisioning::class)->configure($server);
                }
                Gate::forUser(app(PortalAccess::class)->currentUser($actor))->authorize('update', $server);
                $server->forceFill(['bridge_status' => 'installed', 'bridge_error' => null, 'bridge_installed_at' => now(),
                    'bridge_source_sha256' => $configuration['source_sha256'], 'bridge_ca_certificate' => $public ? null : $response['ca_certificate'],
                    'api_token' => $public ? $server->api_token : $response['token'],
                    'api_base_url' => $public ? 'https://'.$server->public_hostname : ProvisionedBridgeTransport::origin($server)])->save();

                return false;
            });
        } catch (Throwable $exception) {
            Log::warning('Bridge provisioning not confirmed', ['server_id' => $serverId, 'exception_type' => $exception::class]);
            $server->refresh();
            if (! in_array($server->hetzner_status, ['deleted', 'deleting'], true)) {
                if ($server->public_https_configuration !== null) {
                    if ($server->bridge_status === 'installed') {
                        return false;
                    }
                    $transient = $exception instanceof PublicEndpointPendingException || $exception instanceof ConnectionException || $exception instanceof LockTimeoutException;
                    $message = $exception instanceof PublicEndpointPendingException ? $exception->getMessage()
                        : ($exception instanceof ValidationException ? collect($exception->errors())->flatten()->first() : 'Öffentliche HTTPS-Einrichtung nicht bestätigt. Bitte erneut versuchen.');
                    $retry = $transient && $server->public_provisioning_attempts < 12;
                    $server->forceFill(['bridge_status' => $retry ? 'pending' : 'failed', 'bridge_error' => $message,
                        'https_status' => $retry ? ($server->https_status ?? 'pending') : 'failed', 'https_error' => $message,
                        'dns_status' => $exception instanceof ValidationException && isset($exception->errors()['dns']) ? 'failed' : $server->dns_status,
                        'dns_error' => $exception instanceof ValidationException ? ($exception->errors()['dns'][0] ?? null) : null,
                        'public_provisioning_next_at' => $retry ? now()->addSeconds(PublicHttpsProvisioning::retryDelay($server->public_provisioning_attempts)) : null])->save();

                    return $retry;
                }
                $message = $exception instanceof ValidationException ? collect($exception->errors())->flatten()->first() : null;
                $server->forceFill(['bridge_status' => 'failed', 'bridge_error' => $message ?? 'Bridge-Installation nicht bestätigt. Bitte Verbindung prüfen und erneut versuchen; vorhandene Daten bleiben erhalten.'])->save();
            }

            return false;
        }
    }

    private function retain(string $bytes): string
    {
        $digest = hash('sha256', $bytes);
        File::ensureDirectoryExists(storage_path('app/private/bridge-distribution'), 0700);
        $path = storage_path('app/private/bridge-distribution/'.$digest.'.zip');
        if (is_link($path)) {
            throw ValidationException::withMessages(['bridge' => 'Ungültiger Installations-Snapshot.']);
        }
        File::replace($path, $bytes, 0600);

        return $digest;
    }

    private function artifact(string $digest): string
    {
        if (! preg_match('/\A[0-9a-f]{64}\z/', $digest)) {
            throw ValidationException::withMessages(['bridge' => 'Ungültiger Installations-Snapshot.']);
        }
        $path = storage_path('app/private/bridge-distribution/'.$digest.'.zip');
        if (! is_file($path) || is_link($path) || filesize($path) > 67108864 || hash_file('sha256', $path) !== $digest) {
            throw ValidationException::withMessages(['bridge' => 'Der gespeicherte Installations-Snapshot fehlt oder wurde verändert. Bitte die Portal-Datensicherung prüfen.']);
        }

        return File::get($path);
    }
}
