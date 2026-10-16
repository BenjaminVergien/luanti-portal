<?php

namespace App\Models;

use Database\Factories\LuantiAccountJobFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['world_request_id', 'luanti_game_instance_id', 'job_id', 'operation', 'state', 'generation', 'base_url', 'instance_identifier', 'account_ids', 'request_payload', 'result', 'error', 'submitted_at'])]
#[Hidden(['request_payload'])]
class LuantiAccountJob extends Model
{
    /** @use HasFactory<LuantiAccountJobFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['request_payload' => 'encrypted', 'result' => 'array', 'account_ids' => 'array', 'generation' => 'integer', 'submitted_at' => 'immutable_datetime'];
    }

    public function instance(): BelongsTo
    {
        return $this->belongsTo(LuantiGameInstance::class, 'luanti_game_instance_id');
    }
}
