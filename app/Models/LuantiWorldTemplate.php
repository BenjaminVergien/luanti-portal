<?php

namespace App\Models;

use Database\Factories\LuantiWorldTemplateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['luanti_server_id', 'name', 'template_identifier', 'revision', 'is_selectable'])]
class LuantiWorldTemplate extends Model
{
    /** @use HasFactory<LuantiWorldTemplateFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['is_selectable' => 'boolean', 'is_available' => 'boolean', 'last_discovered_at' => 'datetime'];
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(LuantiServer::class, 'luanti_server_id');
    }

    public function worldRequests(): HasMany
    {
        return $this->hasMany(WorldRequest::class);
    }
}
