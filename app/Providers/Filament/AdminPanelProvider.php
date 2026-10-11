<?php

namespace App\Providers\Filament;

use App\Filament\Pages\Accounts;
use App\Filament\Pages\CreateAccount;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\EditAccount;
use App\Filament\Pages\Invitations;
use App\Filament\Pages\LuantiServers;
use App\Filament\Pages\LuantiWorlds;
use App\Filament\Pages\Members;
use App\Filament\Pages\Organizations;
use App\Filament\Pages\Requests;
use App\Filament\Pages\WorldRequests;
use App\Http\Middleware\EnsurePortalAccount;
use Filament\Actions\Action;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    protected function configurePanel(Panel $panel): Panel
    {
        return $panel->login()->passwordReset()->brandName('Luanti-Portal')
            ->colors(['primary' => Color::Amber])
            ->userMenuItems([Action::make('access')->label('Meine Zugänge')->url(fn (): string => route('access'))])
            ->middleware([EncryptCookies::class, AddQueuedCookiesToResponse::class, StartSession::class,
                AuthenticateSession::class, ShareErrorsFromSession::class, PreventRequestForgery::class,
                SubstituteBindings::class, DisableBladeIconComponents::class, DispatchServingFilamentEvent::class])
            ->authMiddleware([Authenticate::class, EnsurePortalAccount::class], isPersistent: true);
    }

    public function panel(Panel $panel): Panel
    {
        return $this->configurePanel($panel)->default()->id('admin')->path('admin')
            ->pages([Dashboard::class, Organizations::class, Members::class, Requests::class, Invitations::class, Accounts::class, CreateAccount::class, EditAccount::class, LuantiServers::class, LuantiWorlds::class, WorldRequests::class]);
    }
}
