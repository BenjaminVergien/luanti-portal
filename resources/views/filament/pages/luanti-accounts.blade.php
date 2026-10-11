<x-filament-panels::page>
    <x-filament::section heading="Spielaccounts für {{ $worldRequest->name }}">
        <div class="fi-prose">
            <p>Spielaccounts sind eigenständige Luanti-Zugänge. Sie benötigen keine E-Mail-Adresse und erhalten keinen Zugang zum Verwaltungsportal.</p>
            <p><strong>Einrichtung:</strong> {{ $worldRequest->organization->name }}<br>
                <strong>Aktueller Antrag:</strong> {{ $worldRequest->world->name }} / {{ $worldRequest->world->server->name }},
                {{ $worldRequest->start_date->format('d.m.Y') }} bis einschließlich {{ $worldRequest->end_date->format('d.m.Y') }}.</p>
            @if ($approvedWorld)
                <p><strong>Zuletzt genehmigter Stand:</strong> {{ $approvedWorld->name }} / {{ $approvedWorld->server->name }}, {{ $approvedWindow }}.</p>
            @endif
            <p>Bei Änderungen bleibt der bisher bestätigte Zugang gültig, bis seine Sperrung bestätigt ist. Erst danach wird der neue Zugang übertragen. Gestoppte Welten und ausstehende Rückmeldungen können die Übertragung verzögern.</p>
        </div>
    </x-filament::section>

    @if ($worldRequest->status === \App\Enums\WorldRequestStatus::Approved)
        <form wire:submit="save" class="fi-sc-form">
            {{ $this->form }}

            <x-filament::actions>
                <x-filament::button type="submit" wire:target="save">Anlegen und übertragen</x-filament::button>
            </x-filament::actions>
        </form>
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
