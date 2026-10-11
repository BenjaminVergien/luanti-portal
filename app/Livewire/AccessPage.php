<?php

namespace App\Livewire;

use App\Models\Invitation;
use App\Models\JoinRequest;
use App\Models\Organization;
use App\Services\PortalAccess;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Component;

class AccessPage extends Component
{
    public string $search = '';

    public function mount(): void
    {
        app(PortalAccess::class)->currentUser(auth()->user());
    }

    public function hydrate(): void
    {
        app(PortalAccess::class)->currentUser(auth()->user());
    }

    public function requestJoin(int $organizationId): void
    {
        $this->validate(['search' => ['string', 'max:255']]);
        abort_if(RateLimiter::tooManyAttempts('join:'.auth()->id(), 10), 429);
        app(PortalAccess::class)->requestMembership(auth()->user(), Organization::query()->findOrFail($organizationId));
        RateLimiter::hit('join:'.auth()->id(), 60);
        session()->flash('status', 'Dein Beitrittsantrag wurde eingereicht.');
    }

    public function createOrganization(): void
    {
        $this->search = trim($this->search);
        $this->validate(['search' => ['required', 'string', 'max:255']]);
        $rateLimitKey = 'organization-create:'.auth()->id();
        abort_if(RateLimiter::tooManyAttempts($rateLimitKey, 10), 429);
        RateLimiter::hit($rateLimitKey, 60);

        $organization = app(PortalAccess::class)->createOrganizationForSelf(auth()->user(), $this->search);
        $this->redirectRoute('filament.portal.pages.organization-home', ['tenant' => $organization->id]);
    }

    public function cancelJoin(int $requestId): void
    {
        app(PortalAccess::class)->cancelRequest(auth()->user(), JoinRequest::query()->findOrFail($requestId));
        session()->flash('status', 'Der Antrag wurde zurückgezogen.');
    }

    public function acceptInvitation(): void
    {
        $tokenHash = session('invitation_hash');
        abort_unless(is_string($tokenHash), 404);
        app(PortalAccess::class)->acceptInvitationHash(auth()->user(), $tokenHash);
        session()->forget('invitation_hash');
        session()->flash('status', 'Die Einladung wurde angenommen.');
    }

    public function dismissInvitation(): void
    {
        session()->forget('invitation_hash');
    }

    public function render(): View
    {
        $user = app(PortalAccess::class)->currentUser(auth()->user());
        $tokenHash = session('invitation_hash');
        $invitation = is_string($tokenHash) ? Invitation::query()->where('token_hash', $tokenHash)->first() : null;
        $organizations = trim($this->search) === '' ? collect()
            : Organization::query()->select(['id', 'name'])->where('name', 'like', '%'.mb_substr(trim($this->search), 0, 255).'%')->orderBy('name')->limit(20)->get();

        return view('livewire.access-page', [
            'user' => $user, 'organizations' => $organizations,
            'memberships' => $user->memberships()->with('organization')->get(),
            'joinRequests' => JoinRequest::query()->where('user_id', $user->id)->with('organization')->latest()->get(),
            'invitation' => $invitation,
            'canCreateOrganization' => trim($this->search) !== '' && mb_strlen(trim($this->search)) <= 255
                && Gate::forUser($user)->allows('createForSelf', Organization::class)
                && ! app(PortalAccess::class)->organizationNameExists($this->search),
        ])->layout('components.layouts.access');
    }
}
