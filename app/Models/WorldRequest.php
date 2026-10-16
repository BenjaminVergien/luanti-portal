<?php

namespace App\Models;

use App\Enums\WorldRequestStatus;
use Database\Factories\WorldRequestFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'luanti_world_template_id', 'start_date', 'end_date', 'participant_count'])]
class WorldRequest extends Model
{
    /** @use HasFactory<WorldRequestFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['start_date' => 'date:Y-m-d', 'end_date' => 'date:Y-m-d', 'participant_count' => 'integer',
            'status' => WorldRequestStatus::class, 'decided_at' => 'datetime', 'revision' => 'integer', 'approved_access' => 'array'];
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
        return $this->belongsTo(LuantiWorldTemplate::class, 'luanti_world_template_id');
    }

    public function decisionMaker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function currentGameInstance(): BelongsTo
    {
        return $this->belongsTo(LuantiGameInstance::class, 'current_game_instance_id');
    }

    public function gameInstances(): HasMany
    {
        return $this->hasMany(LuantiGameInstance::class);
    }

    public function latestGameInstance(): ?LuantiGameInstance
    {
        return $this->gameInstances->where('is_archived', false)->sortByDesc('id')->first();
    }

    public function luantiAccounts(): HasMany
    {
        return $this->hasMany(LuantiAccount::class);
    }
}
