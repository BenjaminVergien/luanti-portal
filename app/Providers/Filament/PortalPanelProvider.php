<?php

namespace App\Providers\Filament;

use App\Filament\Auth\Register;
use App\Filament\Pages\Invitations;
use App\Filament\Pages\Members;
use App\Filament\Pages\OrganizationHome;
use App\Filament\Pages\Requests;
use App\Filament\Pages\WorldRequests;
use App\Http\Middleware\EnsureOrganizationAccess;
use App\Http\Middleware\RedirectWithoutOrganization;
use App\Models\Organization;
use Filament\Panel;

class PortalPanelProvider extends AdminPanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $this->configurePanel($panel)->id('portal')->path('portal')
            ->registration(Register::class)->emailVerification()
            ->tenant(Organization::class)->tenantRoutePrefix('einrichtung')
            ->authMiddleware([RedirectWithoutOrganization::class])
            ->tenantMiddleware([EnsureOrganizationAccess::class], isPersistent: true)
            ->pages([OrganizationHome::class, Members::class, Requests::class, Invitations::class, WorldRequests::class]);
    }
}
