<div class="space-y-5">
    <div>
        <p class="text-sm font-medium text-amber-700">Willkommen, {{ $user->name }}</p>
        <h1 class="mt-1 text-2xl font-semibold sm:text-3xl">Meine Zugänge</h1>
        <p class="mt-2 text-stone-600">Hier findest du deine Einrichtungen und persönlichen Einladungen. Über eine Einrichtung kannst du Luanti-Welten für deine Angebote beantragen.</p>
        @if($user->isInternal())
            <a class="mt-4 inline-block rounded-lg bg-stone-900 px-4 py-2 text-white" href="{{ route('filament.admin.pages.dashboard') }}">Interne Verwaltung öffnen</a>
        @endif
    </div>
    @if(session('status'))<div role="status" class="rounded-xl border border-green-200 bg-green-50 p-4">{{ session('status') }}</div>@endif
    @if($errors->any())<div role="alert" class="rounded-xl border border-red-200 bg-red-50 p-4">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif

    @if($invitation)
    <section class="min-w-0 rounded-xl border border-amber-300 bg-amber-50 p-4 sm:p-5">
        <h2 class="text-xl font-semibold">Persönliche Einladung</h2>
        <p class="mt-2 break-words">{{ $invitation->organization?->name ?? 'Interne Verwaltung' }} · {{ ($invitation->membership_role ?? $invitation->internal_role)->label() }}</p>
        <p class="mt-2 break-words text-sm">Für {{ $invitation->email }} · gültig bis {{ $invitation->expires_at->format('d.m.Y H:i') }}</p>
        @if($invitation->isPending() && mb_strtolower($user->email) === $invitation->email)
            <p class="mt-3 text-stone-700">Klicke auf „Einladung annehmen“, um deinen Zugang zu bestätigen. {{ $invitation->organization ? 'Danach erscheint die Einrichtung unter „Meine Einrichtungen“ und du kannst sie direkt öffnen. Ein zusätzlicher Beitrittsantrag ist nicht nötig.' : 'Danach kannst du über „Interne Verwaltung öffnen“ in die Verwaltung wechseln.' }}</p>
            <button wire:click="acceptInvitation" wire:loading.attr="disabled" class="mt-4 min-h-11 w-full rounded-lg bg-amber-600 px-4 py-2 font-medium text-white sm:w-auto">Einladung annehmen</button>
        @elseif(!$invitation->isPending())
            <p class="mt-3">Diese Einladung ist abgelaufen oder bereits erledigt.</p>
        @else
            <p class="mt-3">Melde dich mit der eingeladenen E-Mail-Adresse an, um diese Einladung anzunehmen.</p>
        @endif
        <button wire:click="dismissInvitation" class="mt-3 min-h-11 px-3 underline sm:ml-3">Ausblenden</button>
    </section>
    @endif

    <section class="min-w-0 rounded-xl border border-stone-200 bg-white p-4 sm:p-5">
        <h2 class="text-xl font-semibold">Meine Einrichtungen</h2>
        @if($memberships->isNotEmpty())
            <p class="mt-2 text-stone-600">Dein Zugang ist eingerichtet. Klicke auf „Einrichtung öffnen“, um eine Luanti-Welt zu beantragen oder deine bestehenden Angebote zu verwalten.</p>
        @endif
        @forelse($memberships as $membership)
            <div class="mt-3 flex flex-col gap-3 border-t border-stone-100 pt-3 sm:flex-row sm:items-center sm:justify-between" wire:key="membership-{{ $membership->id }}">
                <div class="min-w-0 break-words"><p class="font-medium">{{ $membership->organization->name }}</p><p class="text-sm text-stone-600">{{ $membership->role->label() }}</p></div>
                <a class="inline-flex min-h-11 shrink-0 items-center justify-center rounded-lg bg-amber-600 px-4 py-2 font-medium text-white" href="{{ route('filament.portal.pages.organization-home', ['tenant' => $membership->organization_id]) }}">Einrichtung öffnen</a>
            </div>
        @empty
            <p class="mt-2 text-stone-600">Du bist noch keiner Einrichtung zugeordnet.</p>
            @if($invitation?->organization && $invitation->isPending())
                <p class="mt-2 text-stone-600">Nimm zuerst deine persönliche Einladung oben an. Dafür benötigst du das Konto mit der eingeladenen E-Mail-Adresse.</p>
            @else
                <p class="mt-2 text-stone-600">Du hast eine Einladung per E-Mail erhalten? Öffne den Link in dieser E-Mail, damit du die Einladung hier annehmen kannst. Ohne Einladung kannst du unten deine Einrichtung suchen oder neu anlegen.</p>
            @endif
        @endforelse
    </section>

    <div class="grid items-start gap-5 md:grid-cols-2">
    <details class="min-w-0 rounded-xl border border-stone-200 bg-white p-4 sm:p-5" @if($memberships->isEmpty() || trim($search) !== '') open @endif>
        <summary class="cursor-pointer text-lg font-semibold"><h2 class="inline">{{ $memberships->isEmpty() ? 'Einrichtung finden oder anlegen' : 'Weitere Einrichtung hinzufügen' }}</h2></summary>
        <p class="mt-3 text-stone-600">Suche nach dem Namen deiner Einrichtung und klicke beim passenden Treffer auf „Beitritt beantragen“. Nach der Freigabe durch die Einrichtungsverwaltung kannst du die Einrichtung öffnen.</p>
        <p class="mt-2 text-stone-600">Ist deine Einrichtung noch nicht dabei, gib ihren vollständigen Namen ein. Anschließend kannst du sie neu anlegen.</p>
        <p class="mt-2 text-sm text-stone-600">Du hast eine persönliche Einladung erhalten? Öffne den Link aus der E-Mail und nimm die Einladung an. Du brauchst keinen zusätzlichen Beitrittsantrag.</p>
        <p class="mt-2 text-sm text-stone-600">Das Luanti-Portal richtet sich an Fachkräfte der Jugendarbeit. Wenn du freiberuflich arbeitest, kannst du deinen eigenen Namen als Einrichtung verwenden.</p>
        <label for="organization-search" class="mt-3 block text-sm text-stone-600">Name der Einrichtung</label>
        <input id="organization-search" type="search" maxlength="255" wire:model.live.debounce.300ms="search" class="mt-2 min-h-11 w-full rounded-lg border border-stone-300 px-3 py-2" placeholder="Einrichtungsnamen eingeben">
        @foreach($organizations as $organization)
            <div class="mt-3 flex flex-wrap items-center justify-between gap-3" wire:key="organization-{{ $organization->id }}">
                <span class="min-w-0 break-words [overflow-wrap:anywhere]">{{ $organization->name }}</span>
                <button wire:click="requestJoin({{ $organization->id }})" wire:loading.attr="disabled" class="min-h-11 rounded-lg border border-stone-300 px-3 py-2 text-sm">Beitritt beantragen</button>
            </div>
        @endforeach
        @if(trim($search) !== '' && $organizations->isEmpty())<p class="mt-3 text-stone-600">Keine Einrichtung gefunden.</p>@endif
        @if($canCreateOrganization)
            <div class="mt-4 border-t border-stone-100 pt-4">
                <p class="text-stone-600">Deine Einrichtung ist noch nicht dabei? Prüfe die Suchtreffer und erstelle sie bei Bedarf neu. Du erhältst die Einrichtungsverwaltung und kannst weitere Fachkräfte einladen.</p>
                <button wire:click="createOrganization" wire:loading.attr="disabled" class="mt-3 min-h-11 max-w-full break-words rounded-lg bg-amber-600 px-4 py-2 font-medium text-white [overflow-wrap:anywhere] disabled:opacity-50">Einrichtung „{{ trim($search) }}“ erstellen</button>
            </div>
        @endif
    </details>

    <section class="min-w-0 rounded-xl border border-stone-200 bg-white p-4 sm:p-5">
        <h2 class="text-lg font-semibold">Meine Beitrittsanträge</h2>
        @if($joinRequests->isNotEmpty())
            <p class="mt-2 text-stone-600">Hier siehst du den Stand deiner Anträge. Sobald ein Antrag genehmigt ist, findest du die Einrichtung oben unter „Meine Einrichtungen“.</p>
        @endif
        @forelse($joinRequests as $joinRequest)
            <div class="mt-4 flex flex-wrap items-center justify-between gap-3 border-t border-stone-100 pt-4" wire:key="request-{{ $joinRequest->id }}">
                <div class="min-w-0 break-words [overflow-wrap:anywhere]"><p class="font-medium">{{ $joinRequest->organization->name }}</p><p class="text-sm text-stone-600">{{ $joinRequest->status->label() }} · {{ $joinRequest->created_at->format('d.m.Y') }}</p></div>
                @if($joinRequest->status === \App\Enums\JoinRequestStatus::Pending)<button wire:click="cancelJoin({{ $joinRequest->id }})" class="min-h-11 text-sm underline">Antrag zurückziehen</button>@endif
            </div>
        @empty
            <p class="mt-3 text-stone-600">Du hast noch keinen Beitrittsantrag gestellt.</p>
            <p class="mt-2 text-sm text-stone-600">Wenn du eine Einladung angenommen hast, ist dein Zugang bereits eingerichtet. Du musst keinen zusätzlichen Antrag stellen.</p>
        @endforelse
    </section>
    </div>
</div>
