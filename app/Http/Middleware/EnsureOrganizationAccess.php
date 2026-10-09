<?php

namespace App\Http\Middleware;

use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureOrganizationAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $tenant = Filament::getTenant();
        abort_unless($tenant !== null && $request->user()?->canAccessTenant($tenant), 404);

        return $next($request);
    }
}
