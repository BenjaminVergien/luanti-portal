<?php

namespace App\Providers;

use App\Http\Middleware\EnsurePortalAccount;
use App\Http\Responses\PortalLoginResponse;
use App\Http\Responses\PortalRegistrationResponse;
use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Filament\Auth\Http\Responses\Contracts\RegistrationResponse;
use Illuminate\Auth\Middleware\EnsureEmailIsVerified;
use Illuminate\Foundation\DevCommands;
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
    public function boot(): void
    {
        Livewire::addPersistentMiddleware([EnsurePortalAccount::class, EnsureEmailIsVerified::class]);
        DevCommands::artisan('serve --host=127.0.0.1 --port=8000 --tries=1', 'server');
    }
}
