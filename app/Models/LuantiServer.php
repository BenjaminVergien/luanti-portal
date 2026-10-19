<?php

namespace App\Models;

use Database\Factories\LuantiServerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['name', 'notes', 'domain', 'game_port', 'api_base_url', 'api_token'])]
#[Hidden(['api_token', 'provisioning_private_key', 'provisioning_public_key', 'hetzner_create_payload', 'provisioning_host_public_key', 'provisioning_cloud_config', 'bridge_configuration', 'bridge_ca_certificate', 'public_https_configuration'])]
class LuantiServer extends Model
{
    /** @use HasFactory<LuantiServerFactory> */
    use HasFactory;

    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'game_port' => 'integer', 'api_token' => 'encrypted',
            'hetzner_server_id' => 'integer', 'hetzner_ssh_key_id' => 'integer',
            'provisioning_private_key' => 'encrypted', 'hetzner_create_payload' => 'array',
            'provisioning_cloud_config' => 'encrypted', 'luanti_verified_at' => 'datetime',
            'bridge_configuration' => 'encrypted:array', 'bridge_installed_at' => 'datetime',
            'public_https_configuration' => 'encrypted:array', 'dns_cleanup_pending' => 'boolean',
            'dns_action_id' => 'integer', 'dns_cleanup_attempts' => 'integer', 'dns_cleanup_next_at' => 'datetime',
            'public_provisioning_attempts' => 'integer', 'public_provisioning_next_at' => 'datetime',
        ];
    }

    public function templates(): HasMany
    {
        return $this->hasMany(LuantiWorldTemplate::class);
    }

    public function gameInstances(): HasMany
    {
        return $this->hasMany(LuantiGameInstance::class);
    }

    public function hetznerStatusLabel(): string
    {
        return match ($this->hetzner_status) {
            null => 'Manuell verwaltet',
            'key_pending' => 'SSH-Zugang wird eingerichtet',
            'creating', 'initializing' => 'Wird erstellt',
            'running' => 'Läuft',
            'off' => 'Ausgeschaltet',
            'starting' => 'Wird gestartet',
            'stopping' => 'Wird gestoppt',
            'deleting' => 'Wird gelöscht',
            'deleted' => 'Gelöscht',
            'create_failed' => 'Erstellung abgelehnt',
            default => 'Status prüfen',
        };
    }

    public function installationStatusLabel(): string
    {
        return match ($this->luanti_installation_status) {
            'pending' => 'Installation angefragt', 'installing' => 'Wird installiert',
            'verified' => 'Engine geprüft', 'failed' => 'Installation fehlgeschlagen',
            'deleted' => 'VM gelöscht', default => 'Nicht eingerichtet',
        };
    }
}
