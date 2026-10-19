<x-filament-panels::page>
    <x-filament::section heading="Spielaccounts für {{ $worldRequest->name }}">
        <div class="fi-prose">
            <p>Spielaccounts sind eigenständige Luanti-Zugänge. Sie benötigen keine E-Mail-Adresse und erhalten keinen Zugang zum Verwaltungsportal.</p>
            <p><strong>Einrichtung:</strong> {{ $worldRequest->organization->name }}<br>
                <strong>Aktueller Antrag:</strong> {{ $worldRequest->world->name }} / {{ $worldRequest->world->server?->name ?? 'Server wird bereitgestellt' }},
                {{ $worldRequest->start_date->format('d.m.Y') }} bis einschließlich {{ $worldRequest->end_date->format('d.m.Y') }}.</p>
            @if ($approvedWorld)
                <p><strong>Zuletzt genehmigter Stand:</strong> {{ $approvedWorld->label() }} / {{ $approvedWorld->server->name }}, {{ $approvedWindow }}.</p>
            @endif
            <p>Spielaccounts gelten nur für die private Spielinstanz. Archivierte Spielstände bleiben erhalten und sind nicht spielbar. Vorlagen enthalten keine Spielaccounts.</p>
            @foreach ($worldRequest->gameInstances as $instance)
                <p><strong>{{ $instance->label() }}</strong>
                    @if ($instance->error)<br>{{ $instance->error }}@endif
                </p>
            @endforeach
            @if ($approvedWorld)
                <p><strong>Verbindung:</strong> {{ $approvedWorld->server->domain }}:{{ $approvedWorld->server->game_port }}</p>
            @endif
        </div>
    </x-filament::section>

    @if ($worldRequest->status === \App\Enums\WorldRequestStatus::Approved && $approvedWorld)
        <form wire:submit="save" class="fi-sc-form">
            {{ $this->form }}

            <x-filament::actions>
                <x-filament::button type="submit" wire:target="save">Anlegen und übertragen</x-filament::button>
            </x-filament::actions>
        </form>
    @elseif ($worldRequest->status === \App\Enums\WorldRequestStatus::Approved)
        <x-filament::section heading="Spielserver wird bereitgestellt">
            Dein Antrag ist genehmigt. Sobald Server und private Spielinstanz bereit sind, kannst du hier Spielaccounts anlegen.
            @if ($worldRequest->provisioning_error)
                <p>{{ $worldRequest->provisioning_error }}</p>
            @endif
        </x-filament::section>
    @else
        <x-filament::section heading="Aktueller Antrag nicht genehmigt">
            Neue Spielaccounts können nach Genehmigung des aktuellen Antrags angelegt werden. Bisherige Accounts und bestätigte Zugänge bleiben unten sichtbar.
        </x-filament::section>
    @endif

    {{ $this->table }}

    <div wire:poll.10s>
        <x-filament::section heading="Letzte Übertragungsaufträge">
            @forelse ($recentJobs as $job)
                <div class="fi-prose" wire:key="account-job-{{ $job['id'] }}">
                    <p><strong>{{ $job['operation'] }} · {{ $job['world'] }} / {{ $job['server'] }}</strong><br>
                        {{ $job['state'] }} · {{ $job['created_at'] }}<br>
                        Auftrag: {{ $job['id'] }}
                        @if ($job['error'])
                            <br>{{ $job['error'] }}
                        @endif
                    </p>
                </div>
            @empty
                Noch keine Übertragungsaufträge vorbereitet.
            @endforelse
        </x-filament::section>
    </div>
</x-filament-panels::page>
