<div class="space-y-8">
    <div>
        <p class="text-sm font-medium text-amber-700">Willkommen, {{ $user->name }}</p>
        <h1 class="mt-2 text-3xl font-semibold">Meine Zugänge</h1>
        <p class="mt-3 text-stone-600">Hier verwaltest du deine Einrichtungsmitgliedschaften, Beitrittsanträge und persönlichen Einladungen.</p>
        @if($user->isInternal())
            <a class="mt-4 inline-block rounded-lg bg-stone-900 px-4 py-2 text-white" href="{{ route('filament.admin.pages.dashboard') }}">Interne Verwaltung öffnen</a>
        @endif
    </div>
    @if(session('status'))<div role="status" class="rounded-xl border border-green-200 bg-green-50 p-4">{{ session('status') }}</div>@endif
    @if($errors->any())<div role="alert" class="rounded-xl border border-red-200 bg-red-50 p-4">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif

    @if($invitation)
    <section class="rounded-xl border border-amber-300 bg-amber-50 p-6">
        <h2 class="text-xl font-semibold">Persönliche Einladung</h2>
        <p class="mt-2">{{ $invitation->organization?->name ?? 'Interne Verwaltung' }} · {{ ($invitation->membership_role ?? $invitation->internal_role)->label() }}</p>
        <p class="mt-2">Für {{ $invitation->email }} · gültig bis {{ $invitation->expires_at->format('d.m.Y H:i') }}</p>
        @if($invitation->isPending() && mb_strtolower($user->email) === $invitation->email)
            <button wire:click="acceptInvitation" wire:loading.attr="disabled" class="mt-4 rounded-lg bg-amber-600 px-4 py-2 font-medium text-white">Einladung annehmen</button>
        @elseif(!$invitation->isPending())
            <p class="mt-3">Diese Einladung ist abgelaufen oder bereits erledigt.</p>
        @else
            <p class="mt-3">Melde dich mit der eingeladenen E-Mail-Adresse an, um diese Einladung anzunehmen.</p>
        @endif
        <button wire:click="dismissInvitation" class="ml-3 underline">Ausblenden</button>
    </section>
    @endif

    <section class="rounded-xl border border-stone-200 bg-white p-6">
        <h2 class="text-xl font-semibold">Meine Einrichtungen</h2>
        @forelse($memberships as $membership)
            <div class="mt-4 flex flex-wrap items-center justify-between gap-3 border-t border-stone-100 pt-4" wire:key="membership-{{ $membership->id }}">
                <div><p class="font-medium">{{ $membership->organization->name }}</p><p class="text-sm text-stone-600">{{ $membership->role->label() }}</p></div>
                <a class="rounded-lg bg-amber-600 px-4 py-2 font-medium text-white" href="{{ route('filament.portal.pages.organization-home', ['tenant' => $membership->organization_id]) }}">Einrichtung öffnen</a>
            </div>
        @empty
            <p class="mt-3 text-stone-600">Du bist noch keiner Einrichtung zugeordnet. Stelle einen Beitrittsantrag oder öffne eine persönliche Einladung.</p>
        @endforelse
    </section>

    <section class="rounded-xl border border-stone-200 bg-white p-6">
        <h2 class="text-xl font-semibold">Einrichtung finden</h2>
        <label for="organization-search" class="mt-3 block text-sm text-stone-600">Name der Einrichtung</label>
        <input id="organization-search" type="search" maxlength="255" wire:model.live.debounce.300ms="search" class="mt-2 w-full rounded-lg border border-stone-300 px-3 py-2" placeholder="Einrichtungsnamen eingeben">
        @foreach($organizations as $organization)
            <div class="mt-3 flex items-center justify-between gap-3" wire:key="organization-{{ $organization->id }}">
                <span>{{ $organization->name }}</span>
                <button wire:click="requestJoin({{ $organization->id }})" wire:loading.attr="disabled" class="rounded-lg border border-stone-300 px-3 py-2 text-sm">Beitritt beantragen</button>
            </div>
        @endforeach
        @if(trim($search) !== '' && $organizations->isEmpty())<p class="mt-3 text-stone-600">Keine Einrichtung gefunden.</p>@endif
    </section>

    <section class="rounded-xl border border-stone-200 bg-white p-6">
        <h2 class="text-xl font-semibold">Meine Beitrittsanträge</h2>
        @forelse($joinRequests as $joinRequest)
            <div class="mt-4 flex flex-wrap items-center justify-between gap-3 border-t border-stone-100 pt-4" wire:key="request-{{ $joinRequest->id }}">
                <div><p class="font-medium">{{ $joinRequest->organization->name }}</p><p class="text-sm text-stone-600">{{ $joinRequest->status->label() }} · {{ $joinRequest->created_at->format('d.m.Y') }}</p></div>
                @if($joinRequest->status === \App\Enums\JoinRequestStatus::Pending)<button wire:click="cancelJoin({{ $joinRequest->id }})" class="text-sm underline">Antrag zurückziehen</button>@endif
            </div>
        @empty
            <p class="mt-3 text-stone-600">Du hast noch keinen Beitrittsantrag gestellt.</p>
        @endforelse
    </section>
</div>
