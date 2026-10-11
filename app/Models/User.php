<?php

namespace App\Models;

use App\Enums\InternalRole;
use App\Enums\MembershipRole;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasTenants;
use Filament\Panel;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser, HasTenants, MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected function casts(): array
    {
        return ['email_verified_at' => 'datetime', 'password' => 'hashed', 'is_active' => 'boolean', 'internal_role' => InternalRole::class];
    }

    public function isGlobalAdmin(): bool
    {
        return $this->is_active && $this->hasVerifiedEmail() && $this->internal_role === InternalRole::GlobalAdmin;
    }

    public function isInternal(): bool
    {
        return $this->is_active && $this->hasVerifiedEmail() && $this->internal_role !== null;
    }

    public function organizations(): BelongsToMany
    {
        return $this->belongsToMany(Organization::class, 'organization_memberships')->withPivot('role')->withTimestamps();
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(OrganizationMembership::class);
    }

    public function worldRequests(): HasMany
    {
        return $this->hasMany(WorldRequest::class);
    }

    public function manages(Organization $organization): bool
    {
        return $this->is_active && $this->hasVerifiedEmail() && ($this->isInternal() || $this->memberships()->where('organization_id', $organization->id)->where('role', MembershipRole::Manager)->exists());
    }

    public function canAccessPanel(Panel $panel): bool
    {
        $current = $this->fresh();

        return $current !== null && $current->is_active && match ($panel->getId()) {
            'admin' => $current->isInternal(), 'portal' => true, default => false
        };
    }

    public function getTenants(Panel $panel): Collection
    {
        $current = $this->fresh();
        if ($current === null || ! $current->is_active || ! $current->hasVerifiedEmail()) {
            return collect();
        }

        return $current->isGlobalAdmin() ? Organization::query()->orderBy('name')->get() : $current->organizations()->orderBy('name')->get();
    }

    public function canAccessTenant(Model $tenant): bool
    {
        $current = $this->fresh();

        return $tenant instanceof Organization && $current !== null && $current->is_active && $current->hasVerifiedEmail()
            && ($current->isGlobalAdmin() || $current->organizations()->whereKey($tenant->getKey())->exists());
    }
}
