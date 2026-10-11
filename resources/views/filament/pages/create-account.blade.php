<x-filament-panels::page>
    <form wire:submit="save" class="fi-sc-form">
        {{ $this->form }}

        <x-filament::actions>
            <x-filament::button type="submit" wire:target="save">Speichern</x-filament::button>
            <x-filament::button tag="a" :href="\App\Filament\Pages\Accounts::getUrl(panel: 'admin')" color="gray">Abbrechen</x-filament::button>
        </x-filament::actions>
    </form>
</x-filament-panels::page>
