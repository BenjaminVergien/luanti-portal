<?php

namespace App\Models;

use Database\Factories\LuantiAccountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['world_request_id', 'username', 'normalized_username', 'role', 'initial_password'])]
#[Hidden(['initial_password'])]
class LuantiAccount extends Model
{
    /** @use HasFactory<LuantiAccountFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['initial_password' => 'encrypted'];
    }

    public function worldRequest(): BelongsTo
    {
        return $this->belongsTo(WorldRequest::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(LuantiAccountAssignment::class);
    }
}
