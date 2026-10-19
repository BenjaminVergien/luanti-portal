<?php

namespace App\Providers\Filament;

use App\Filament\Auth\Register;
use App\Filament\Pages\Invitations;
use App\Filament\Pages\LuantiAccounts;
use App\Filament\Pages\Members;
use App\Filament\Pages\OrganizationHome;
use App\Filament\Pages\Requests;
use App\Filament\Pages\WorldRequests;
use App\Http\Middleware\EnsureOrganizationAccess;
use App\Http\Middleware\RedirectWithoutOrganization;
use App\Models\Organization;
use Filament\Auth\Pages\EmailVerification\EmailVerificationPrompt;
use Filament\Panel;
use Filament\View\PanelsRenderHook;
use Illuminate\Contracts\View\View;

class PortalPanelProvider extends AdminPanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $this->configurePanel($panel)->id('portal')->path('portal')
            ->registration(Register::class)->emailVerification()
            ->renderHook(PanelsRenderHook::AUTH_REGISTER_FORM_BEFORE, fn (): View => view('filament.auth.registration-help'), scopes: Register::class)
            ->renderHook(PanelsRenderHook::SIMPLE_PAGE_END, fn (): View => view('filament.auth.verification-help'), scopes: EmailVerificationPrompt::class)
            ->tenant(Organization::class)->tenantRoutePrefix('einrichtung')
            ->authMiddleware([RedirectWithoutOrganization::class])
            ->tenantMiddleware([EnsureOrganizationAccess::class], isPersistent: true)
            ->pages([OrganizationHome::class, Members::class, Requests::class, Invitations::class, WorldRequests::class, LuantiAccounts::class]);
    }
}
