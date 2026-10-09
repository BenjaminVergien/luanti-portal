<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">{{ filament()->getTenant()->name }}</x-slot>
        <p>Du arbeitest in dieser Einrichtung. Über das Einrichtungsmenü kannst du zu deinen weiteren Einrichtungen wechseln.</p>
        <p class="mt-4">Weltanträge und Spielzugänge werden im nächsten Ausbauschritt ergänzt.</p>
        <p class="mt-4"><a href="{{ route('access') }}" class="text-primary-600 underline">Meine Mitgliedschaften und Beitrittsanträge</a></p>
    </x-filament::section>
</x-filament-panels::page>
