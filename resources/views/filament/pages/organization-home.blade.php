<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">{{ filament()->getTenant()->name }}</x-slot>
        <p>Hier planst du Luanti-Angebote für deine Einrichtung. Über das Einrichtungsmenü kannst du zu deinen weiteren Einrichtungen wechseln.</p>
        <ol class="mt-4 list-decimal space-y-3 pl-5">
            <li><strong>Welt beantragen:</strong> Wähle eine Weltvorlage, den Zeitraum und die voraussichtliche Teilnehmendenzahl.</li>
            <li><strong>Genehmigung abwarten:</strong> Die Portalverwaltung prüft deinen Antrag. Den aktuellen Stand findest du unter Weltanträge.</li>
            <li><strong>Spielaccounts anlegen:</strong> Nach der Genehmigung können die antragstellende Person und die Einrichtungsverwaltung Spielnamen und Rollen für das Angebot anlegen. Teilnehmende benötigen kein Portalkonto.</li>
            <li><strong>Gemeinsam spielen:</strong> Sobald das Spiel läuft und die Accounts freigegeben sind, meldet ihr euch im Luanti-Client an. Serveradresse, Port und Startpasswörter findest du bei den Spielaccounts. Der Zugang gilt für den genehmigten Zeitraum.</li>
        </ol>
        <div class="mt-5">
            <x-filament::button tag="a" :href="\App\Filament\Pages\WorldRequests::getUrl(panel: 'portal', tenant: filament()->getTenant())">Welt beantragen und Weltanträge ansehen</x-filament::button>
        </div>
        <p class="mt-4"><a href="{{ route('access') }}" class="text-primary-600 underline">Meine Mitgliedschaften und Beitrittsanträge</a></p>
    </x-filament::section>
</x-filament-panels::page>
