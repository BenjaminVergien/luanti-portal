<?php

namespace App\Services;

use App\Models\LuantiServer;
use Illuminate\Validation\ValidationException;

class LuantiDockerBootstrap
{
    public function __construct(private SshKeyPair $keys) {}

    public function prepare(LuantiServer $server): void
    {
        if ($server->luanti_docker_image === null || $server->provisioning_cloud_config !== null) {
            return;
        }
        if (! preg_match('/\Aghcr\.io\/luanti-org\/luanti:[A-Za-z0-9_][A-Za-z0-9_.-]{0,127}\z/', $server->luanti_docker_image)) {
            throw ValidationException::withMessages(['hetzner' => 'Bitte zuerst ein gültiges Luanti-Image in den Einstellungen wählen.']);
        }
        $hostKey = $this->keys->generate();
        $config = [
            'disable_root' => false, 'ssh_deletekeys' => true,
            'ssh_keys' => ['rsa_private' => $hostKey['private'], 'rsa_public' => $hostKey['public']],
            'ssh_quiet_keygen' => true, 'ssh' => ['emit_keys_to_console' => false],
            'write_files' => [
                [
                    'path' => '/etc/luanti-portal/installation.json', 'owner' => 'root:root', 'permissions' => '0600',
                    'content' => json_encode(['reference' => $server->hetzner_reference, 'image' => $server->luanti_docker_image], JSON_THROW_ON_ERROR),
                ],
                [
                    'path' => '/usr/local/lib/luanti-portal/install.py', 'owner' => 'root:root', 'permissions' => '0700',
                    'encoding' => 'b64', 'content' => base64_encode(file_get_contents(resource_path('provisioning/install_luanti.py'))),
                ],
            ],
            'runcmd' => [['python3', '/usr/local/lib/luanti-portal/install.py']],
        ];
        $cloudConfig = "#cloud-config\n".json_encode($config, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        if (strlen($cloudConfig) > 32768) {
            throw ValidationException::withMessages(['hetzner' => 'Die Installationskonfiguration überschreitet die Hetzner-Größenbegrenzung.']);
        }
        $server->forceFill([
            'provisioning_host_public_key' => $hostKey['public'], 'provisioning_cloud_config' => $cloudConfig,
            'luanti_installation_status' => 'pending',
        ])->save();
    }
}
