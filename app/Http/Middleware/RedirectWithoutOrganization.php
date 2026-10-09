<?php

namespace App\Http\Middleware;

use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RedirectWithoutOrganization
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->routeIs('filament.portal.tenant') && $request->user()->hasVerifiedEmail()
            && $request->user()->getTenants(Filament::getPanel('portal'))->isEmpty()) {
            return redirect()->route('access');
        }

        return $next($request);
    }
}
