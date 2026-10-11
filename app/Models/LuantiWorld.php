<?php

namespace App\Models;

use Database\Factories\LuantiWorldFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['luanti_server_id', 'name', 'world_identifier', 'is_selectable'])]
class LuantiWorld extends Model
{
    /** @use HasFactory<LuantiWorldFactory> */
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
