<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">Einrichtungen und Zugänge</x-slot>
        <p>Verwalte Einrichtungsmitgliedschaften, prüfe Beitrittsanträge und lade Fachkräfte oder Einrichtungsverwaltungen ein.</p>
        <div class="mt-4 flex flex-wrap gap-3">
            <x-filament::button tag="a" :href="route('filament.admin.pages.einrichtungen')">Einrichtungen</x-filament::button>
            <x-filament::button tag="a" :href="route('filament.admin.pages.mitgliedschaften')" color="gray">Mitgliedschaften</x-filament::button>
            <x-filament::button tag="a" :href="route('filament.admin.pages.beitrittsantraege')" color="gray">Beitrittsanträge</x-filament::button>
            <x-filament::button tag="a" :href="route('filament.admin.pages.einladungen')" color="gray">Einladungen</x-filament::button>
        </div>
    </x-filament::section>
    @if(auth()->user()->fresh()->isGlobalAdmin())
        <x-filament::section>
            <x-slot name="heading">Konten und interne Rollen</x-slot>
            <p>Neue interne Rollen werden durch persönliche Einladungen vergeben. In der Kontenverwaltung kannst du Konten sperren und bestehende interne Rechte zurücknehmen.</p>
            <div class="mt-4">
                <x-filament::button tag="a" :href="route('filament.admin.pages.konten')">Konten verwalten</x-filament::button>
            </div>
        </x-filament::section>
    @endif
</x-filament-panels::page>
