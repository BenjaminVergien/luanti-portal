<?php

use App\Services\CanonicalUrl;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\SetRequestForConsole;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

$app = Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustHosts(
            at: fn (): array => ['^'.preg_quote(parse_url(config('app.url'), PHP_URL_HOST), '/').'$'],
            subdomains: false,
        );
        $middleware->redirectGuestsTo(fn () => route('filament.portal.auth.login'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();

$app->beforeBootstrapping(
    SetRequestForConsole::class,
    fn (Application $app): string => $app->make(CanonicalUrl::class)->origin(),
);

return $app;
