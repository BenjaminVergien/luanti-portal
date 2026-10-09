<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePortalAccount
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user()?->fresh();
        abort_unless($user !== null && $user->is_active, 403);

        return $next($request);
    }
}
