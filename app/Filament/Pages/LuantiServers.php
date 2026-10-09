<?php

namespace App\Filament\Pages;

use App\Models\LuantiServer;
use App\Services\LuantiServerRegistry;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Gate;

class LuantiServers extends AccessTablePage
{
    protected static ?string $title = 'Luanti-Server';

    protected static ?string $slug = 'server';

    public static function canAccess(): bool
    {
        $actor = auth()->user();

        return Filament::getCurrentPanel()?->getId() === 'admin' && $actor !== null
            && Gate::forUser($actor)->allows('viewAny', LuantiServer::class);
    }

    protected function getHeaderActions(): array
    {
        return [Action::make('create')->label('Server anlegen')
            ->schema($this->serverFields())
            ->action(function (array $data): void {
                app(LuantiServerRegistry::class)->create($this->actor(), $data);
                $this->saved('Server angelegt');
            })];
    }

    public function table(Table $table): Table
    {
        return $table->query(fn () => app(LuantiServerRegistry::class)->query($this->actor()))->columns([
            TextColumn::make('name')->label('Name')->searchable()->sortable(),
            TextColumn::make('domain')->label('Domain')->sortable(),
            TextColumn::make('game_port')->label('Spiel-Port'),
            TextColumn::make('api_base_url')->label('HTTPS-API-URL')->placeholder('Nicht konfiguriert')->wrap(),
            IconColumn::make('has_api_token')->label('API-Token hinterlegt')
                ->getStateUsing(fn (LuantiServer $record): bool => filled($record->getRawOriginal('api_token')))->boolean(),
        ])->recordActions([
            Action::make('edit')->label('Bearbeiten')
                ->fillForm(fn (LuantiServer $record): array => $record->only(['name', 'notes', 'domain', 'game_port', 'api_base_url']))
                ->schema($this->serverFields())
                ->action(function (LuantiServer $record, array $data): void {
                    app(LuantiServerRegistry::class)->update($this->actor(), $record, $data);
                    $this->saved();
                }),
            Action::make('removeToken')->label('API-Token entfernen')->color('danger')->requiresConfirmation()
                ->modalHeading('API-Token entfernen?')->modalDescription('Der gespeicherte API-Token wird aus dem Portal entfernt.')
                ->visible(fn (LuantiServer $record): bool => filled($record->getRawOriginal('api_token')))
                ->action(function (LuantiServer $record): void {
                    app(LuantiServerRegistry::class)->removeToken($this->actor(), $record);
                    $this->saved('API-Token entfernt');
                }),
            Action::make('delete')->label('Löschen')->color('danger')->requiresConfirmation()
                ->modalHeading('Server löschen?')->modalDescription('Der Servereintrag und seine gespeicherten Zugangsdaten werden aus dem Portal entfernt.')
                ->action(function (LuantiServer $record): void {
                    app(LuantiServerRegistry::class)->delete($this->actor(), $record);
                    $this->saved('Server gelöscht');
                }),
        ])->emptyStateHeading('Noch keine Luanti-Server');
    }

    /** @return list<TextInput|Textarea> */
    private function serverFields(): array
    {
        return [
            TextInput::make('name')->label('Name')->required()->maxLength(255),
            Textarea::make('notes')->label('Notizen')->rows(3),
            TextInput::make('domain')->label('Domain')->required()->maxLength(253)->rules(LuantiServerRegistry::domainRules())
                ->helperText('Domain des Spielservers, z. B. luanti.example.org (ohne https://, Port oder Pfad).'),
            TextInput::make('game_port')->label('Spiel-Port')->required()->integer()->minValue(1)->maxValue(65535),
            TextInput::make('api_base_url')->label('HTTPS-API-URL')->maxLength(2048)->rules(['url:https'])
                ->helperText('Optionaler Endpunkt der zukünftigen Bridge. Die Verbindung wird noch nicht geprüft.'),
            TextInput::make('api_token')->label('API-Token')->password()->autocomplete('off')
                ->helperText('Optionaler Token der Bridge. Leer lassen, um einen bereits gespeicherten Token beizubehalten.'),
        ];
    }
}
