<?php

namespace App\Models;

use App\Enums\WorldRequestStatus;
use Database\Factories\WorldRequestFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['name', 'luanti_world_id', 'start_date', 'end_date', 'participant_count'])]
class WorldRequest extends Model
{
    /** @use HasFactory<WorldRequestFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['start_date' => 'date:Y-m-d', 'end_date' => 'date:Y-m-d', 'participant_count' => 'integer',
            'status' => WorldRequestStatus::class, 'decided_at' => 'datetime', 'revision' => 'integer'];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function applicant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function world(): BelongsTo
    {
        return $this->belongsTo(LuantiWorld::class, 'luanti_world_id');
    }

    public function decisionMaker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
