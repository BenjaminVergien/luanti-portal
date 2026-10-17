<?php

namespace App\Services;

use App\Enums\InternalRole;
use App\Models\LuantiServer;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RuntimeException;

class BridgeDevelopment
{
    public function __construct(private HetznerVirtualMachines $vms, private LuantiSshClient $ssh) {}

    public function check(string $source): string
    {
        $result = Process::timeout(300)->run([$this->python(), '-B', $this->source($source).'/tools/development.py', 'check']);
        if (! $result->successful()) {
            throw new RuntimeException('Portable bridge checks failed. '.substr($result->output().$result->errorOutput(), -12000));
        }

        return $result->output().$result->errorOutput();
    }

    /** @return array<string, mixed> */
    public function deploy(string $operation, int $serverId, string $source, string $gameSource): array
    {
        if (! app()->environment('local') || ! in_array($operation, ['test', 'up'], true)) {
            throw new RuntimeException('Bridge development deployment is available only in APP_ENV=local.');
        }
        $source = $this->source($source);
        if (! is_dir($gameSource) || ! is_file($gameSource.'/game.conf')) {
            throw new RuntimeException('Supply reviewed Minetest Game content with --game-source or BRIDGE_DEV_GAME_SOURCE.');
        }
        $actor = User::query()->where('internal_role', InternalRole::GlobalAdmin)->get()->first(fn (User $user): bool => $user->isGlobalAdmin());
        if ($actor === null) {
            throw new RuntimeException('Create a verified local global administrator before deploying.');
        }
        $server = $this->vms->refresh($actor, LuantiServer::query()->findOrFail($serverId));
        if ($server->hetzner_status !== 'running' || $server->luanti_installation_status !== 'verified' || blank($server->luanti_image_id)) {
            throw new RuntimeException('The selected VM must be running and its Luanti installation verified through the portal.');
        }
        $directory = storage_path('app/private/bridge-development');
        File::ensureDirectoryExists($directory, 0700);

        return Cache::lock('hetzner:vm:'.$server->id, 2100)->block(1, function () use ($operation, $source, $gameSource, $directory, $server): array {
            $name = $directory.'/bundle-'.bin2hex(random_bytes(12));
            try {
                $manifest = $this->package([$this->python(), '-B', $source.'/tools/development.py', 'bundle', '--output', $name.'.zip']);
                $game = $this->package([$this->python(), '-B', $source.'/tools/development.py', 'game-bundle', '--source', $gameSource, '--output', $name.'-game.zip']);
                $receiptPath = $directory.'/server-'.$server->id.'-tested.json';
                if ($operation === 'up') {
                    $receipt = is_file($receiptPath) ? json_decode(file_get_contents($receiptPath), true, flags: JSON_THROW_ON_ERROR) : [];
                    if (($receipt['source_sha256'] ?? null) !== $manifest['sha256'] || ($receipt['game_sha256'] ?? null) !== $game['archive_sha256']
                        || ($receipt['image_id'] ?? null) !== $server->luanti_image_id || ($receipt['reference'] ?? null) !== $server->hetzner_reference) {
                        throw new RuntimeException('Run bridge test against this exact source, game content and VM before bridge up.');
                    }
                }
                $payload = [
                    'operation' => $operation, 'reference' => $server->hetzner_reference, 'image_id' => $server->luanti_image_id,
                    'source' => base64_encode(file_get_contents($name.'.zip')), 'game' => base64_encode(file_get_contents($name.'-game.zip')),
                    'source_archive_sha256' => $manifest['archive_sha256'], 'game_archive_sha256' => $game['archive_sha256'],
                ];
                $script = 'PAYLOAD = __import__("json").loads(__import__("base64").b64decode("'.base64_encode(json_encode($payload, JSON_THROW_ON_ERROR)).'"))'."\n"
                    .file_get_contents(resource_path('provisioning/bridge_development.py'));
                $output = $this->ssh->execute($server, $script, 1800, 65536);
                $response = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
                if (($response['ok'] ?? false) !== true || ($response['source_sha256'] ?? null) !== $manifest['sha256'] || ($response['image_id'] ?? null) !== $server->luanti_image_id) {
                    throw new RuntimeException('VM bridge '.$operation.' failed: '.($response['error'] ?? 'Invalid development result.'));
                }
                if ($operation === 'test') {
                    $response['reference'] = $server->hetzner_reference;
                    File::put($receiptPath, json_encode($response, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
                    chmod($receiptPath, 0600);
                } else {
                    if (! preg_match('/\A[A-Za-z0-9_-]{43}\z/', $response['token'] ?? '') || ! str_contains($response['ca_certificate'] ?? '', '-----BEGIN CERTIFICATE-----')) {
                        throw new RuntimeException('VM returned invalid private development credentials.');
                    }
                    File::put(config('services.bridge_development.ca_certificate'), $response['ca_certificate']);
                    chmod(config('services.bridge_development.ca_certificate'), 0600);
                    $server->forceFill(['api_base_url' => config('services.bridge_development.origin'), 'api_token' => $response['token']])->save();
                    unset($response['token'], $response['ca_certificate']);
                }

                return $response;
            } finally {
                File::delete([$name.'.zip', $name.'-game.zip']);
            }
        });
    }

    public function tunnel(int $serverId): void
    {
        $this->ssh->tunnel(LuantiServer::query()->findOrFail($serverId));
    }

    private function source(string $source): string
    {
        $source = realpath($source);
        if ($source === false || ! is_file($source.'/tools/development.py')) {
            throw new RuntimeException('Configure the local Git bridge checkout with --source or BRIDGE_DEV_SOURCE.');
        }

        return $source;
    }

    private function python(): string
    {
        return config('services.bridge_development.python_binary');
    }

    /**
     * @param  array<int, string>  $arguments
     * @return array<string, mixed>
     */
    private function package(array $arguments): array
    {
        $result = Process::timeout(120)->run($arguments);
        if (! $result->successful()) {
            throw new RuntimeException('Bridge source or game packaging failed. '.substr($result->errorOutput(), -2000));
        }

        return json_decode($result->output(), true, flags: JSON_THROW_ON_ERROR);
    }
}
