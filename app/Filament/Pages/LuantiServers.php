<?php

namespace App\Filament\Pages;

use App\Jobs\CleanupLuantiDns;
use App\Models\LuantiServer;
use App\Services\BridgeProvisioning;
use App\Services\HetznerVirtualMachines;
use App\Services\LuantiApiConnectionChecker;
use App\Services\LuantiDockerInstallation;
use App\Services\LuantiServerRegistry;
use App\Services\PortalSettings;
use App\Services\ProvisionedBridgeTransport;
use App\Services\PublicEndpointPendingException;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Facades\Filament;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Bus\UniqueLock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

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
        return [
            Action::make('createVm')->label('Hetzner-VM erstellen')
                ->modalHeading('Hetzner-VM erstellen')
                ->modalDescription('Erstellt eine kostenpflichtige VM mit eigenem SSH-Schlüssel. Docker und das ausgewählte Luanti-Image werden automatisch installiert. Wenn die Bridge-Installation in den Einstellungen aktiviert ist, richtet das Portal anschließend die Bridge aus GitHub ein.')
                ->modalSubmitActionLabel('Kostenpflichtige VM erstellen')
                ->schema([
                    TextEntry::make('selected_luanti_image')->label('Luanti-Image')
                        ->state(fn (): string => app(PortalSettings::class)->luantiDockerImage() ?? 'Keines gewählt – nur VM erstellen'),
                    TextEntry::make('selected_bridge')->label('Bridge')
                        ->state(function (): string {
                            $settings = app(PortalSettings::class)->bridge();

                            return $settings['bridge_enabled'] ? $settings['bridge_repository'].' · '.$settings['bridge_ref'] : 'Automatische Installation deaktiviert';
                        }),
                    TextInput::make('name')->label('VM-Name')->required()->minLength(2)->maxLength(253)
                        ->default(fn (): string => HetznerVirtualMachines::generatedName())
                        ->suffixAction(Action::make('generateName')->label('Neuen Namen vorschlagen')->icon('heroicon-o-arrow-path')
                            ->action(fn (Set $set) => $set('name', HetznerVirtualMachines::generatedName())))
                        ->rules(HetznerVirtualMachines::nameRules())
                        ->mutateStateForValidationUsing(fn (?string $state): ?string => $state === null ? null : strtolower(trim($state)))
                        ->validationMessages(HetznerVirtualMachines::nameValidationMessages())
                        ->hintIcon('heroicon-o-information-circle', tooltip: HetznerVirtualMachines::NameHelp)
                        ->extraInputAttributes(['title' => HetznerVirtualMachines::NameHelp])
                        ->helperText(fn (): string => app(PortalSettings::class)->publicHttps()['public_https_enabled']
                            ? 'API-Adresse: VM-Name.'.app(PortalSettings::class)->publicHttps()['dns_zone'].'. Ohne Punkte, höchstens 63 Zeichen.'
                            : 'Buchstaben, Ziffern, Bindestriche und Punkte, z. B. test-2.0. Details am Info-Symbol.'),
                    TextInput::make('server_type')->label('Servertyp')->required()->default('cx23')->maxLength(63)
                        ->helperText('Typbezeichnung aus Hetzner, z. B. cx23. Preis und Verfügbarkeit im Hetzner-Projekt prüfen.'),
                    TextInput::make('location')->label('Standort')->required()->default('nbg1')->maxLength(63),
                    TextInput::make('image')->label('Betriebssystem-Image')->required()->default('ubuntu-24.04')->maxLength(63),
                ])
                ->action(function (array $data): void {
                    $this->performVmAction(fn (): LuantiServer => app(HetznerVirtualMachines::class)->create($this->actor(), $data));
                }),
            Action::make('create')->label('Server anlegen')
                ->schema($this->serverFields())
                ->action(function (array $data): void {
                    app(LuantiServerRegistry::class)->create($this->actor(), $data);
                    $this->saved('Server angelegt');
                })];
    }

    public function table(Table $table): Table
    {
        return $table->query(fn () => app(LuantiServerRegistry::class)->query($this->actor()))->poll('10s')
            ->stackedOnMobile()->recordAction('details')->columns([
                TextColumn::make('name')->label('Name')->searchable()->sortable()->wrap()->weight('medium'),
                TextColumn::make('domain')->label('Domain')->placeholder('Noch nicht eingerichtet')->sortable()->wrap(),
                TextColumn::make('hetzner_status')->label('VM')->wrap()
                    ->getStateUsing(fn (LuantiServer $record): string => $record->hetznerStatusLabel())
                    ->color(fn (LuantiServer $record): string => filled($record->hetzner_error) || $record->hetzner_status === 'create_failed' ? 'danger' : 'gray')
                    ->description(fn (LuantiServer $record): ?string => filled($record->hetzner_error) ? 'Fehler · siehe Details' : null),
                TextColumn::make('hetzner_server_id')->label('Hetzner-ID')->placeholder('–')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('hetzner_ipv4')->label('IPv4')->placeholder('–')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('hetzner_ipv6')->label('IPv6-Netz')->placeholder('–')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('hetzner_error')->label('VM-Fehler')->placeholder('–')->wrap()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('luanti_installation_status')->label('Luanti')->wrap()
                    ->getStateUsing(fn (LuantiServer $record): string => $record->installationStatusLabel())
                    ->color(fn (LuantiServer $record): string => filled($record->luanti_installation_error) || $record->luanti_installation_status === 'failed' ? 'danger' : 'gray')
                    ->description(fn (LuantiServer $record): ?string => filled($record->luanti_installation_error) ? 'Fehler · siehe Details' : null),
                TextColumn::make('luanti_docker_image')->label('Luanti-Image')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('bridge_status')->label('Bridge')->wrap()
                    ->getStateUsing(fn (LuantiServer $record): string => $this->bridgeStatusLabel($record->bridge_status))
                    ->color(fn (LuantiServer $record): string => filled($record->bridge_error) || $record->bridge_status === 'failed' ? 'danger' : 'gray')
                    ->description(fn (LuantiServer $record): ?string => filled($record->bridge_error) ? 'Fehler · siehe Details' : null),
                TextColumn::make('bridge_commit')->label('Bridge-Commit')->limit(12)->tooltip(fn (LuantiServer $record): ?string => $record->bridge_commit)->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('bridge_error')->label('Bridge-Installation')->wrap()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('dns_status')->label('DNS')->placeholder('Nicht eingerichtet')
                    ->formatStateUsing(fn (string $state): string => $this->endpointStatusLabel($state))->wrap()
                    ->color(fn (LuantiServer $record): string => filled($record->dns_error) ? 'danger' : 'gray'),
                TextColumn::make('https_status')->label('HTTPS')->placeholder('Nicht eingerichtet')
                    ->formatStateUsing(fn (string $state): string => $this->endpointStatusLabel($state))->wrap()
                    ->color(fn (LuantiServer $record): string => filled($record->https_error) ? 'danger' : 'gray'),
                TextColumn::make('luanti_engine_version')->label('Engine-Version')->placeholder('Noch nicht geprüft')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('luanti_verified_at')->label('Installation geprüft am')->dateTime('d.m.Y H:i')->timezone('Europe/Berlin')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('luanti_installation_error')->label('Installation/Prüfung')->wrap()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('game_port')->label('Spiel-Port')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('api_base_url')->label('HTTPS-API-Verbindung')->placeholder('Nicht konfiguriert')->wrap()
                    ->formatStateUsing(fn (string $state, LuantiServer $record): string => $record->bridge_status === 'installed'
                        && $state === ProvisionedBridgeTransport::origin($record) ? 'Privat über SSH' : $state)->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('has_api_token')->label('API-Token hinterlegt')
                    ->getStateUsing(fn (LuantiServer $record): bool => filled($record->getRawOriginal('api_token')))->boolean()->toggleable(isToggledHiddenByDefault: true),
            ])->recordActions([ActionGroup::make([
                Action::make('retryDnsCleanup')->label('DNS-Bereinigung erneut versuchen')
                    ->visible(fn (LuantiServer $record): bool => $record->dns_cleanup_pending && $record->hetzner_status === 'deleted')
                    ->action(function (LuantiServer $record): void {
                        Gate::forUser($this->actor())->authorize('update', $record);
                        Cache::lock('hetzner:vm:'.$record->id, 200)->block(1, function () use ($record): void {
                            $record->refresh();
                            if ($record->hetzner_status !== 'deleted' || ! $record->dns_cleanup_pending) {
                                return;
                            }
                            $record->forceFill(['dns_cleanup_attempts' => 0, 'dns_cleanup_next_at' => null, 'dns_error' => null])->save();
                            app(UniqueLock::class)->release(new CleanupLuantiDns($record->id));
                            CleanupLuantiDns::dispatch($record->id)->afterCommit();
                        });
                        Notification::make()->title('DNS-Bereinigung erneut angefragt')->success()->send();
                    }),
                Action::make('details')->label('Details')->icon('heroicon-o-information-circle')
                    ->modalHeading(fn (LuantiServer $record): string => $record->name)
                    ->modalWidth('3xl')->modalSubmitAction(false)->modalCancelActionLabel('Schließen')
                    ->schema($this->serverDetails()),
                Action::make('retryBridge')->label('Bridge-Installation erneut versuchen')
                    ->visible(fn (LuantiServer $record): bool => $record->bridge_configuration !== null
                        && $record->bridge_status === 'failed' && $record->hetzner_status === 'running')
                    ->action(function (LuantiServer $record): void {
                        app(BridgeProvisioning::class)->retry($this->actor(), $record);
                        Notification::make()->title('Bridge-Installation erneut angefragt')->success()->send();
                    }),
                Action::make('verifyInstallation')->label('Installation prüfen')
                    ->visible(fn (LuantiServer $record): bool => $record->luanti_docker_image !== null
                        && $record->provisioning_host_public_key !== null
                        && in_array($record->hetzner_status, ['running', 'initializing', 'starting'], true))
                    ->action(function (LuantiServer $record): void {
                        try {
                            $result = app(LuantiDockerInstallation::class)->verify($this->actor(), $record);
                        } catch (ValidationException $exception) {
                            Notification::make()->title('Installation konnte nicht geprüft werden')
                                ->body(collect($exception->errors())->flatten()->first())->danger()->send();

                            return;
                        }
                        Notification::make()->title($result['title'])->body($result['body'])
                            ->status($result['successful'] ? 'success' : 'warning')->send();
                    }),
                Action::make('retryKeySetup')->label('VM-Einrichtung fortsetzen')
                    ->visible(fn (LuantiServer $record): bool => $record->hetzner_status === 'key_pending')
                    ->action(function (LuantiServer $record): void {
                        $this->performVmAction(fn (): LuantiServer => app(HetznerVirtualMachines::class)->retryKeySetup($this->actor(), $record));
                    }),
                Action::make('refreshVm')->label('VM-Status aktualisieren')
                    ->visible(fn (LuantiServer $record): bool => $record->hetzner_reference !== null
                        && ! in_array($record->hetzner_status, ['deleted', 'create_failed', 'key_pending'], true))
                    ->action(function (LuantiServer $record): void {
                        $this->performVmAction(fn (): LuantiServer => app(HetznerVirtualMachines::class)->refresh($this->actor(), $record));
                    }),
                Action::make('deleteVm')->label('Hetzner-VM löschen')->color('danger')->requiresConfirmation()
                    ->modalHeading('Hetzner-VM unwiderruflich löschen?')
                    ->modalDescription('Löscht die VM und ihre lokale Festplatte bei Hetzner unwiderruflich, einschließlich aller Spielwelten, Spielaccounts und Spielfortschritte. Es wird keine Sicherung erstellt. Vorlagen-ZIPs in der Portalbibliothek bleiben erhalten. Der Portaleintrag und die bisherige Historie bleiben erhalten.')
                    ->schema([
                        TextInput::make('confirmation_name')->label('Servername zur Bestätigung')
                            ->helperText(fn (LuantiServer $record): string => 'Zum Löschen exakt „'.$record->name.'“ eingeben.')
                            ->required()->maxLength(255)->rules(fn (LuantiServer $record): array => [Rule::in([$record->name])])
                            ->validationMessages(['required' => 'Bitte den Servernamen zur Bestätigung eingeben.',
                                'in' => 'Der Servername stimmt nicht überein. Bitte den Namen exakt eingeben.']),
                    ])
                    ->modalSubmitActionLabel('VM und Weltdaten unwiderruflich löschen')
                    ->visible(fn (LuantiServer $record): bool => $record->hetzner_reference !== null
                        && $record->hetzner_status !== 'deleted')
                    ->action(function (LuantiServer $record, array $data): void {
                        $this->performVmAction(fn (): LuantiServer => app(HetznerVirtualMachines::class)->delete($this->actor(), $record, $data['confirmation_name']));
                    }),
                Action::make('checkConnection')->label('API-Verbindung prüfen')
                    ->action(function (LuantiServer $record): void {
                        $this->notifyConnectionResult(app(LuantiApiConnectionChecker::class)->check($this->actor(), server: $record));
                    }),
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
                    ->modalHeading('Server löschen?')->modalDescription('Entfernt den Server aus der Serverliste und bereinigt ungenutzte Vorlagenkopien. Bestehende Anträge und Spielhistorie sowie Vorlagen-ZIPs der Portal-Bibliothek bleiben erhalten.')
                    ->visible(fn (LuantiServer $record): bool => ! $record->dns_cleanup_pending && ($record->hetzner_reference === null
                        || (in_array($record->hetzner_status, ['deleted', 'create_failed'], true)
                            && $record->getRawOriginal('provisioning_private_key') === null)))
                    ->action(function (LuantiServer $record): void {
                        try {
                            app(LuantiServerRegistry::class)->delete($this->actor(), $record);
                        } catch (ValidationException $exception) {
                            Notification::make()->title('Server kann nicht gelöscht werden')->body($exception->errors()['server'][0])->danger()->send();

                            return;
                        }
                        $this->saved('Server gelöscht');
                    }),
            ])->label('Aktionen')->icon('heroicon-m-chevron-down')->button()->color('gray')])
            ->emptyStateHeading('Noch keine Luanti-Server');
    }

    private function bridgeStatusLabel(?string $status): string
    {
        return match ($status) {
            'pending' => 'Wartet auf Luanti', 'installing' => 'Wird installiert', 'installed' => 'Installiert und geprüft',
            'failed' => 'Installation nicht bestätigt', 'deleted' => 'VM gelöscht', default => 'Nicht eingerichtet',
        };
    }

    private function endpointStatusLabel(string $status): string
    {
        return match ($status) {
            'pending' => 'Einrichtung angefragt', 'creating' => 'Wird angelegt', 'ready' => 'Bereit',
            'configuring' => 'Wird eingerichtet', 'waiting_dns' => 'Wartet auf DNS', 'verifying' => 'Wird geprüft',
            'failed' => 'Einrichtung fehlgeschlagen', 'cleanup_failed' => 'Bereinigung ausstehend', 'deleted' => 'Gelöscht',
            default => $status,
        };
    }

    /** @return list<Section> */
    private function serverDetails(): array
    {
        return [
            Section::make('Server')->columns(2)->columnSpanFull()->schema([
                TextEntry::make('domain')->label('Domain')->placeholder('Noch nicht eingerichtet'),
                TextEntry::make('game_port')->label('Spiel-Port'),
                TextEntry::make('notes')->label('Notizen')->placeholder('–')->columnSpanFull(),
            ]),
            Section::make('Virtuelle Maschine')->columns(2)->columnSpanFull()->schema([
                TextEntry::make('hetzner_status')->label('VM-Status')->state(fn (LuantiServer $record): string => $record->hetznerStatusLabel()),
                TextEntry::make('hetzner_server_id')->label('Hetzner-ID')->placeholder('–'),
                TextEntry::make('hetzner_ipv4')->label('IPv4')->placeholder('–'),
                TextEntry::make('hetzner_ipv6')->label('IPv6-Netz')->placeholder('–'),
                TextEntry::make('hetzner_error')->label('VM-Fehler')->placeholder('–')->color('danger')->columnSpanFull(),
            ]),
            Section::make('Luanti-Installation')->columns(2)->columnSpanFull()->schema([
                TextEntry::make('luanti_installation_status')->label('Status')->state(fn (LuantiServer $record): string => $record->installationStatusLabel()),
                TextEntry::make('luanti_engine_version')->label('Engine-Version')->placeholder('Noch nicht geprüft'),
                TextEntry::make('luanti_docker_image')->label('Luanti-Image')->placeholder('–')->columnSpanFull(),
                TextEntry::make('luanti_verified_at')->label('Installation geprüft am')->dateTime('d.m.Y H:i')->timezone('Europe/Berlin')->placeholder('Noch nicht geprüft'),
                TextEntry::make('luanti_installation_error')->label('Installation/Prüfung')->placeholder('–')->color('danger')->columnSpanFull(),
            ]),
            Section::make('Bridge')->columns(2)->columnSpanFull()->schema([
                TextEntry::make('bridge_status')->label('Status')->state(fn (LuantiServer $record): string => $this->bridgeStatusLabel($record->bridge_status)),
                TextEntry::make('bridge_installed_at')->label('Installiert am')->dateTime('d.m.Y H:i')->timezone('Europe/Berlin')->placeholder('–'),
                TextEntry::make('bridge_commit')->label('Bridge-Commit')->placeholder('–')->columnSpanFull(),
                TextEntry::make('bridge_error')->label('Bridge-Installation')->placeholder('–')->color('danger')->columnSpanFull(),
            ]),
            Section::make('API und Weltvorlagen')->columns(2)->columnSpanFull()->schema([
                TextEntry::make('public_hostname')->label('Öffentlicher Hostname')->placeholder('Nicht eingerichtet'),
                TextEntry::make('dns_status')->label('DNS')->formatStateUsing(fn (string $state): string => $this->endpointStatusLabel($state))->placeholder('Nicht eingerichtet'),
                TextEntry::make('https_status')->label('HTTPS')->formatStateUsing(fn (string $state): string => $this->endpointStatusLabel($state))->placeholder('Nicht eingerichtet'),
                TextEntry::make('dns_error')->label('DNS-Fehler')->placeholder('–')->color('danger')->columnSpanFull(),
                TextEntry::make('https_error')->label('HTTPS-Fehler')->placeholder('–')->color('danger')->columnSpanFull(),
                TextEntry::make('api_base_url')->label('HTTPS-API-Verbindung')->placeholder('Nicht konfiguriert')
                    ->formatStateUsing(fn (string $state, LuantiServer $record): string => $record->bridge_status === 'installed'
                        && $state === ProvisionedBridgeTransport::origin($record) ? 'Privat über SSH' : $state),
                TextEntry::make('has_api_token')->label('API-Token hinterlegt')
                    ->state(fn (LuantiServer $record): string => filled($record->getRawOriginal('api_token')) ? 'Ja' : 'Nein'),
            ]),
        ];
    }

    /** @param Closure(): LuantiServer $operation */
    private function performVmAction(Closure $operation): void
    {
        try {
            $server = $operation();
        } catch (PublicEndpointPendingException $exception) {
            Notification::make()->title('DNS-Anfrage noch nicht möglich')->body($exception->getMessage())->warning()->send();

            return;
        } catch (ValidationException $exception) {
            if (! isset($exception->errors()['hetzner'])) {
                Notification::make()->title('VM-Eingaben prüfen')->body(collect($exception->errors())->flatten()->first())->danger()->send();
                $statePath = $this->getMountedActionSchema()?->getStatePath();
                throw ValidationException::withMessages(collect($exception->errors())->mapWithKeys(
                    fn (array $messages, string $field): array => [filled($statePath) ? $statePath.'.'.$field : $field => $messages],
                )->all());
            }
            Notification::make()->title('Hetzner-Anfrage nicht möglich')->body($exception->errors()['hetzner'][0])->danger()->send();

            return;
        }
        Notification::make()->title($server->hetznerStatusLabel())
            ->body($server->hetzner_error ?? ($server->hetzner_status === 'deleted'
                ? 'Die VM wurde bei Hetzner gelöscht.' : ($server->hetzner_status === 'key_pending'
                    ? 'Mit „VM-Einrichtung fortsetzen“ erneut versuchen.' : 'Mit „VM-Status aktualisieren“ den aktuellen Stand abrufen.')))
            ->status($server->hetzner_error === null ? 'success' : 'warning')->send();
    }

    /** @return list<TextInput|Textarea|Actions> */
    private function serverFields(): array
    {
        return [
            TextInput::make('name')->label('Name')->required()->maxLength(255),
            Textarea::make('notes')->label('Notizen')->rows(3),
            TextInput::make('domain')->label('Domain')->required()->maxLength(253)->rules(LuantiServerRegistry::domainRules())
                ->helperText('Domain des Spielservers, z. B. luanti.example.org (ohne https://, Port oder Pfad).'),
            TextInput::make('game_port')->label('Spiel-Port')->required()->integer()->minValue(1)->maxValue(65535),
            TextInput::make('api_base_url')->label('HTTPS-API-URL')->maxLength(2048)->rules(['url:https'])
                ->helperText('HTTPS-Basis-URL der Bridge, z. B. https://bensfoundry.de. Die API-Pfade /v1/... werden bei der Prüfung ergänzt.'),
            TextInput::make('api_token')->label('API-Token')->password()->autocomplete('off')
                ->helperText('Optionaler Token der Bridge. Leer lassen, um einen bereits gespeicherten Token beizubehalten.'),
            Actions::make([
                Action::make('checkConnection')->label('API-Verbindung prüfen')
                    ->action(function (Get $get, ?LuantiServer $record): void {
                        $this->notifyConnectionResult(app(LuantiApiConnectionChecker::class)->check($this->actor(), [
                            'api_base_url' => $get('api_base_url'), 'api_token' => $get('api_token'),
                        ], $record));
                    }),
            ])->key('connectionCheck'),
        ];
    }

    /** @param array{successful: bool, title: string, body: string} $result */
    private function notifyConnectionResult(array $result): void
    {
        Notification::make()->title($result['title'])->body($result['body'])
            ->status($result['successful'] ? 'success' : 'danger')->send();
    }
}
