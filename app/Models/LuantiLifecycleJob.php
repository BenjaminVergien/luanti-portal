<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['luanti_game_instance_id', 'job_id', 'operation', 'state', 'base_url', 'request_payload', 'result', 'error'])]
class LuantiLifecycleJob extends Model
{
    protected function casts(): array
    {
        return ['result' => 'array'];
    }

    public function instance(): BelongsTo
    {
        return $this->belongsTo(LuantiGameInstance::class, 'luanti_game_instance_id');
    }
}
