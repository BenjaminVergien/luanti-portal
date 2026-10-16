<?php

namespace App\Models;

use Database\Factories\LuantiGameInstanceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['world_request_id', 'luanti_world_template_id', 'luanti_server_id', 'instance_identifier', 'template_identifier', 'template_revision', 'state', 'is_prepared', 'is_archived', 'error'])]
class LuantiGameInstance extends Model
{
    /** @use HasFactory<LuantiGameInstanceFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['is_prepared' => 'boolean', 'is_archived' => 'boolean'];
    }

    public function worldRequest(): BelongsTo
    {
        return $this->belongsTo(WorldRequest::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(LuantiWorldTemplate::class, 'luanti_world_template_id');
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(LuantiServer::class, 'luanti_server_id');
    }

    public function lifecycleJobs(): HasMany
    {
        return $this->hasMany(LuantiLifecycleJob::class);
    }

    public function label(): string
    {
        return $this->instance_identifier.' · '.$this->statusLabel();
    }

    public function statusLabel(): string
    {
        return match ($this->state) {
            'preparing' => 'Wird vorbereitet', 'starting' => 'Wird gestartet', 'running' => 'Läuft',
            'stopped' => 'Gestoppt', 'archived' => 'Archiviert', default => 'Fehlgeschlagen',
        };
    }
}
