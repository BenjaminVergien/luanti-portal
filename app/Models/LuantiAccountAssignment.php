<?php

namespace App\Models;

use Database\Factories\LuantiAccountAssignmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['luanti_account_id', 'luanti_game_instance_id', 'normalized_username', 'applied_window', 'applied_admitted', 'is_provisioned', 'outcome_uncertain', 'error'])]
class LuantiAccountAssignment extends Model
{
    /** @use HasFactory<LuantiAccountAssignmentFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['applied_window' => 'array', 'applied_admitted' => 'boolean', 'is_provisioned' => 'boolean', 'outcome_uncertain' => 'boolean'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(LuantiAccount::class, 'luanti_account_id');
    }

    public function instance(): BelongsTo
    {
        return $this->belongsTo(LuantiGameInstance::class, 'luanti_game_instance_id');
    }
}
