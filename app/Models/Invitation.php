<?php

namespace App\Models;

use App\Enums\InternalRole;
use App\Enums\MembershipRole;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Hidden(['token_hash'])]
class Invitation extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['membership_role' => MembershipRole::class, 'internal_role' => InternalRole::class, 'expires_at' => 'datetime', 'accepted_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    public function isPending(): bool
    {
        return $this->accepted_at === null && $this->revoked_at === null && $this->expires_at->isFuture();
    }

    public function statusLabel(): string
    {
        return match (true) {
            $this->accepted_at !== null => 'Angenommen', $this->revoked_at !== null => 'Widerrufen', $this->expires_at->isPast() => 'Abgelaufen', default => 'Offen'
        };
    }
}
