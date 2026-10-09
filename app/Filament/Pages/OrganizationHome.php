<?php

namespace App\Filament\Pages;

use App\Models\Organization;
use App\Services\PortalAccess;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Page;
use Filament\Panel;
use Illuminate\Support\Facades\Gate;

class OrganizationHome extends Page
{
    protected static ?string $title = 'Meine Einrichtung';

    protected string $view = 'filament.pages.organization-home';

    public static function getRoutePath(Panel $panel): string
    {
        return '/';
    }

    public static function canAccess(): bool
    {
        $organization = Filament::getTenant();

        return $organization instanceof Organization && (auth()->user()?->canAccessTenant($organization) ?? false);
    }

    protected function getHeaderActions(): array
    {
        return [Action::make('rename')->label('Einrichtung bearbeiten')
            ->visible(fn (): bool => Gate::forUser(app(PortalAccess::class)->currentUser(auth()->user()))->allows('update', Filament::getTenant()))
            ->fillForm(fn (): array => ['name' => Filament::getTenant()->name])
            ->schema([TextInput::make('name')->label('Name')->required()->maxLength(255)])
            ->action(function (array $data): void {
                app(PortalAccess::class)->renameOrganization(auth()->user(), Filament::getTenant(), $data['name']);
            })];
    }
}
