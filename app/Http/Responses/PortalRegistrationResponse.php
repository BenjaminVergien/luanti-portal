<?php

namespace App\Http\Responses;

use Filament\Auth\Http\Responses\Contracts\RegistrationResponse;
use Illuminate\Http\RedirectResponse;
use Livewire\Features\SupportRedirects\Redirector;

class PortalRegistrationResponse implements RegistrationResponse
{
    public function toResponse($request): RedirectResponse|Redirector
    {
        return redirect()->route('access');
    }
}
