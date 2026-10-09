<?php

namespace App\Filament\Pages;

use App\Enums\MembershipRole;
use App\Models\Organization;
use App\Models\User;
use App\Services\PortalAccess;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Illuminate\Database\Eloquent\Builder;

abstract class AccessTablePage extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.access-table';

    public static function canAccess(): bool
    {
        $actor = auth()->user()?->fresh();
        if ($actor === null || ! $actor->is_active || ! $actor->hasVerifiedEmail()) {
            return false;
        }
        if (Filament::getCurrentPanel()?->getId() === 'admin') {
            return $actor->isInternal();
        }
        $organization = Filament::getTenant();

        return $organization instanceof Organization && $actor->canAccessTenant($organization) && $actor->manages($organization);
    }

    protected function actor(): User
    {
        return app(PortalAccess::class)->currentUser(auth()->user());
    }

    protected function isInternalPanel(): bool
    {
        return Filament::getCurrentPanel()->getId() === 'admin';
    }

    protected function scopedQuery(Builder $query): Builder
    {
        abort_unless(static::canAccess(), 403);
        if (! $this->isInternalPanel()) {
            $query->where('organization_id', Filament::getTenant()->id);
        }

        return $query;
    }

    protected function organizationField(): Select
    {
        return Select::make('organization_id')->label('Einrichtung')
            ->options(fn (): array => Organization::query()->orderBy('name')->pluck('name', 'id')->all())
            ->searchable()->required(fn (): bool => $this->isInternalPanel())->visible(fn (): bool => $this->isInternalPanel());
    }

    protected function organizationFromData(array $data): Organization
    {
        if ($this->isInternalPanel()) {
            abort_unless($this->actor()->isInternal(), 403);

            return Organization::query()->findOrFail($data['organization_id'] ?? null);
        }
        $organization = Filament::getTenant();
        abort_unless($organization instanceof Organization && $this->actor()->canAccessTenant($organization), 404);
        if (isset($data['organization_id'])) {
            abort_unless((int) $data['organization_id'] === $organization->id, 404);
        }

        return $organization;
    }

    protected function membershipRoleField(): Select
    {
        return Select::make('membership_role')->label('Rolle')->options(fn (): array => $this->actor()->isInternal()
            ? MembershipRole::options() : [MembershipRole::Staff->value => MembershipRole::Staff->label()])
            ->default(MembershipRole::Staff->value)->required();
    }

    protected function saved(string $message = 'Gespeichert'): void
    {
        Notification::make()->title($message)->success()->send();
    }
}
