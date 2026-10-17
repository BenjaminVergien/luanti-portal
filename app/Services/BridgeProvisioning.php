<?php

namespace App\Services;

use App\Jobs\ProvisionLuantiBridge;
use App\Models\LuantiServer;
use App\Models\User;
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
        Cache::lock('hetzner:vm:'.$server->id, 1000)->block(1, function () use ($actor, $server): void {
            $server = $server->fresh();
            if ($server->bridge_configuration === null || ! in_array($server->bridge_status, ['failed', 'pending', 'installing'], true)
                || in_array($server->hetzner_status, ['deleted', 'deleting', 'create_failed'], true)) {
                throw ValidationException::withMessages(['bridge' => 'Für diesen Server ist keine wiederholbare Bridge-Installation vorhanden.']);
            }
            $server->forceFill(['bridge_status' => 'pending', 'bridge_error' => null, 'bridge_requested_by' => $actor->id])->save();
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
            if ($server->luanti_installation_status !== 'verified') {
                $result = $this->engine->verify($actor, $server);
                $server->refresh();
                if (! $result['successful']) {
                    if ($server->luanti_installation_status !== 'failed' && $server->created_at->greaterThan(now()->subMinutes(30))) {
                        return true;
                    }
                    throw ValidationException::withMessages(['bridge' => 'Die Luanti-Installation ist nicht bereit. Bitte Installation prüfen und danach die Bridge-Installation erneut starten.']);
                }
            } else {
                $server = app(HetznerVirtualMachines::class)->refresh($actor, $server);
            }

            return Cache::lock('hetzner:vm:'.$serverId, 1000)->block(1, function () use ($server, $actor): bool {
                $server = $server->fresh();
                Gate::forUser(app(PortalAccess::class)->currentUser($actor))->authorize('update', $server);
                if ($server->hetzner_status !== 'running') {
                    return false;
                }
                $configuration = $server->bridge_configuration;
                $payload = ['reference' => $server->hetzner_reference, 'image_id' => $server->luanti_image_id, 'commit' => $server->bridge_commit,
                    'admin_name' => $configuration['admin_name'], 'source_sha256' => $configuration['source_sha256'],
                    'source_archive_sha256' => $configuration['source_archive_sha256'], 'game_archive_sha256' => $configuration['game_archive_sha256'],
                    'source' => base64_encode($this->artifact($configuration['source_archive_sha256'])),
                    'game' => base64_encode($this->artifact($configuration['game_archive_sha256']))];
                $server->forceFill(['bridge_status' => 'installing', 'bridge_error' => null])->save();
                $script = 'PAYLOAD = __import__("json").loads(__import__("base64").b64decode("'.base64_encode(json_encode($payload, JSON_THROW_ON_ERROR)).'"))'."\n"
                    .file_get_contents(resource_path('provisioning/install_bridge.py'));
                $response = json_decode($this->ssh->execute($server, $script, 900, 65536), true, flags: JSON_THROW_ON_ERROR);
                if (($response['ok'] ?? false) !== true || ($response['commit'] ?? null) !== $server->bridge_commit
                    || ($response['source_sha256'] ?? null) !== $configuration['source_sha256'] || ($response['image_id'] ?? null) !== $server->luanti_image_id
                    || ! preg_match('/\A[A-Za-z0-9_-]{43}\z/', $response['token'] ?? '')
                    || ! is_string($response['ca_certificate'] ?? null) || strlen($response['ca_certificate']) > 16384
                    || openssl_x509_read($response['ca_certificate']) === false) {
                    throw ValidationException::withMessages(['bridge' => 'Die Bridge-Installation konnte nicht bestätigt werden. Ein erneuter Versuch verwendet denselben Commit und erhält vorhandene Daten.']);
                }
                Gate::forUser(app(PortalAccess::class)->currentUser($actor))->authorize('update', $server);
                $server->forceFill(['bridge_status' => 'installed', 'bridge_error' => null, 'bridge_installed_at' => now(),
                    'bridge_source_sha256' => $configuration['source_sha256'], 'bridge_ca_certificate' => $response['ca_certificate'],
                    'api_token' => $response['token'], 'api_base_url' => ProvisionedBridgeTransport::origin($server)])->save();

                return false;
            });
        } catch (Throwable $exception) {
            Log::warning('Bridge provisioning not confirmed', ['server_id' => $serverId, 'exception_type' => $exception::class]);
            $server->refresh();
            if (! in_array($server->hetzner_status, ['deleted', 'deleting'], true)) {
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
