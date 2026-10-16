<?php

namespace App\Providers;

use App\Http\Middleware\EnsurePortalAccount;
use App\Http\Responses\PortalLoginResponse;
use App\Http\Responses\PortalRegistrationResponse;
use App\Services\CanonicalUrl;
use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Filament\Auth\Http\Responses\Contracts\RegistrationResponse;
use Illuminate\Auth\Middleware\EnsureEmailIsVerified;
use Illuminate\Foundation\DevCommands;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(LoginResponse::class, PortalLoginResponse::class);
        $this->app->bind(RegistrationResponse::class, PortalRegistrationResponse::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(CanonicalUrl $canonicalUrl): void
    {
        $origin = $canonicalUrl->origin();
        URL::useOrigin($origin);
        URL::forceScheme(parse_url($origin, PHP_URL_SCHEME));

        Livewire::addPersistentMiddleware([EnsurePortalAccount::class, EnsureEmailIsVerified::class]);
        DevCommands::artisan('serve --host=127.0.0.1 --port=8000 --tries=1', 'server');
    }
}
