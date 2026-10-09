<?php

namespace App\Http\Responses;

use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Filament\Facades\Filament;
use Illuminate\Http\RedirectResponse;
use Livewire\Features\SupportRedirects\Redirector;

class PortalLoginResponse implements LoginResponse
{
    public function toResponse($request): RedirectResponse|Redirector
    {
        $fallback = Filament::getCurrentPanel()->getId() === 'admin' ? route('filament.admin.pages.dashboard') : route('access');

        return redirect()->intended($fallback);
    }
}
