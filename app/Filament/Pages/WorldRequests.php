<?php

namespace App\Filament\Pages;

use App\Enums\WorldRequestStatus;
use App\Models\LuantiAccount;
use App\Models\LuantiServer;
use App\Models\LuantiWorldTemplate;
use App\Models\Organization;
use App\Models\WorldRequest;
use App\Services\HetznerVirtualMachines;
use App\Services\LuantiGameInstances;
use App\Services\WorldRequestProvisioning;
use App\Services\WorldRequests as WorldRequestService;
use Closure;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class WorldRequests extends AccessTablePage
{
    protected static ?string $title = 'Weltanträge';

    protected static ?string $slug = 'weltantraege';

    public static function canAccess(): bool
    {
        $actor = auth()->user();
        if ($actor === null) {
            return false;
        }
        $organization = Filament::getTenant();

        return match (Filament::getCurrentPanel()?->getId()) {
            'admin' => Gate::forUser($actor)->allows('viewAny', WorldRequest::class),
            'portal' => $organization instanceof Organization
                && Gate::forUser($actor)->allows('viewAny', [WorldRequest::class, $organization]),
            default => false,
        };
    }

    private function requestOrganization(): ?Organization
    {
        abort_unless(static::canAccess(), 403);

        return $this->isInternalPanel() ? null : Filament::getTenant();
    }

    protected function getHeaderActions(): array
    {
        return [Action::make('create')->label('Welt beantragen')
            ->visible(fn (): bool => ! $this->isInternalPanel()
                && Gate::forUser($this->actor())->allows('create', [WorldRequest::class, $this->requestOrganization()]))
            ->schema($this->requestFields())
            ->action(function (array $data, Schema $schema): void {
                $organization = $this->requestOrganization();
                abort_unless($organization instanceof Organization, 403);
                $this->saveChange($schema, fn () => app(WorldRequestService::class)->create($this->actor(), $organization, $data), 'Weltantrag eingereicht');
            })];
    }

    public function table(Table $table): Table
    {
        return $table->query(fn () => app(WorldRequestService::class)->query($this->actor(), $this->requestOrganization()))->poll('10s')
            ->defaultSort('created_at', 'desc')->columns([
                TextColumn::make('organization.name')->label('Einrichtung')->searchable()->visible(fn (): bool => $this->isInternalPanel()),
                TextColumn::make('name')->label('Name')->searchable()->sortable(),
                TextColumn::make('world.name')->label('Weltvorlage')->searchable(),
                TextColumn::make('server_name')->label('Server')->state(fn (WorldRequest $record): ?string => $record->world->server?->name ?? $record->provisionedServer?->name)
                    ->placeholder('Wird nach Genehmigung bereitgestellt'),
                TextColumn::make('currentGameInstance.instance_identifier')->label('Spielinstanz')->placeholder('Noch nicht vorbereitet'),
                TextColumn::make('instance_status')->label('Spielstatus')->state(fn (WorldRequest $record): string => $record->latestGameInstance()?->statusLabel()
                    ?? $record->currentGameInstance?->statusLabel() ?? ($record->provisioning_error !== null ? 'Bereitstellung fehlgeschlagen'
                        : ($record->provisioning_configuration !== null ? 'Server wird bereitgestellt' : 'Wartet auf Genehmigung'))),
                TextColumn::make('preparation_error')->label('Vorbereitung')->state(fn (WorldRequest $record): ?string => $record->provisioning_error
                    ?? $record->latestGameInstance()?->error ?? $record->currentGameInstance?->error)->wrap(),
                TextColumn::make('start_date')->label('Startdatum')->date('d.m.Y')->sortable(),
                TextColumn::make('end_date')->label('Enddatum')->date('d.m.Y')->sortable(),
                TextColumn::make('participant_count')->label('Teilnehmende')->sortable(),
                TextColumn::make('applicant.name')->label('Beantragt von')->searchable(),
                TextColumn::make('status')->label('Status')->badge()
                    ->formatStateUsing(fn (WorldRequestStatus $state): string => $state->label())
                    ->color(fn (WorldRequestStatus $state): string => match ($state) {
                        WorldRequestStatus::Pending => 'warning', WorldRequestStatus::Approved => 'success', WorldRequestStatus::Rejected => 'danger',
                    }),
                TextColumn::make('decisionMaker.name')->label('Entschieden von')->placeholder('–'),
                TextColumn::make('decided_at')->label('Entschieden am')->dateTime('d.m.Y H:i')->timezone('Europe/Berlin')->placeholder('–'),
            ])->filters([SelectFilter::make('status')->label('Status')->options(WorldRequestStatus::options())])
            ->recordActions([
                Action::make('spielaccounts')->label('Spielaccounts')->icon('heroicon-o-user-group')
                    ->visible(fn (WorldRequest $record): bool => LuantiAccounts::canAccess() && Gate::forUser($this->actor())->allows('manageAccounts', $record)
                        && ($record->status === WorldRequestStatus::Approved || $record->approved_access !== null
                            || LuantiAccount::query()->where('world_request_id', $record->id)->exists()))
                    ->url(fn (WorldRequest $record): string => LuantiAccounts::getUrl(['worldRequest' => $record->id],
                        panel: Filament::getCurrentPanel()->getId(), tenant: $this->requestOrganization())),
                Action::make('edit')->label('Bearbeiten')
                    ->visible(fn (WorldRequest $record): bool => Gate::forUser($this->actor())->allows('update', $record)
                        && ($record->provisioning_configuration === null || $record->approved_access !== null))
                    ->fillForm(fn (WorldRequest $record): array => [
                        ...$record->only(['name', 'luanti_world_template_id', 'participant_count', 'revision']),
                        'start_date' => $record->start_date->toDateString(), 'end_date' => $record->end_date->toDateString(),
                    ])
                    ->schema($this->requestFields(editing: true))
                    ->modalDescription('Änderungen am Zeitraum erfordern eine erneute Freigabe. Ein Vorlagenwechsel ist eine eigene Aktion und erstellt ein frisches Spiel.')
                    ->action(function (WorldRequest $record, array $data, Schema $schema): void {
                        $this->guardRecord($record);
                        $this->saveChange($schema, fn () => app(WorldRequestService::class)->update($this->actor(), $record, $data, (int) $data['revision']), 'Weltantrag gespeichert');
                    }),
                $this->decisionAction('approve', 'Genehmigen', true),
                $this->decisionAction('reject', 'Ablehnen', false),
                Action::make('retryProvisioning')->label('Serverbereitstellung erneut prüfen')->requiresConfirmation()
                    ->visible(fn (WorldRequest $record): bool => $this->isInternalPanel() && $record->status === WorldRequestStatus::Approved
                        && $record->provisioning_configuration !== null && $record->approved_access === null
                        && $record->provisioning_error !== null
                        && Gate::forUser($this->actor())->allows('create', LuantiServer::class))
                    ->modalDescription('Prüft die Bereitstellung für diesen Antrag erneut. Eine bereits zugeordnete VM wird weiterverwendet.')
                    ->action(function (WorldRequest $record, ?Schema $schema): void {
                        $this->guardRecord($record);
                        $this->saveChange($schema, fn () => app(WorldRequestProvisioning::class)->retry($this->actor(), $record), 'Serverbereitstellung wird erneut geprüft');
                    }),
                Action::make('changeTemplate')->label('Weltvorlage wechseln')->requiresConfirmation()
                    ->visible(fn (WorldRequest $record): bool => $record->status === WorldRequestStatus::Approved && $record->approved_access !== null
                        && Gate::forUser($this->actor())->allows('update', $record))
                    ->modalDescription('Das bisherige Spiel wird archiviert. Die neue Welt startet ohne bisherigen Spielfortschritt. Spielnamen und Rollen bleiben erhalten; es gelten wieder die gespeicherten Startpasswörter. Genehmigung und Zeitraum bleiben bestehen.')
                    ->fillForm(fn (WorldRequest $record): array => ['revision' => $record->revision])
                    ->schema([Hidden::make('revision')->required(), Select::make('luanti_world_template_id')->label('Neue Weltvorlage')->required()
                        ->options(fn (WorldRequest $record): array => LuantiWorldTemplate::query()
                            ->where('luanti_server_id', $record->approved_access['server_id'])->where('is_available', true)->where('is_selectable', true)
                            ->whereNotNull('revision')->whereKeyNot($record->luanti_world_template_id)->pluck('name', 'id')->all())])
                    ->action(function (WorldRequest $record, array $data, Schema $schema): void {
                        $this->guardRecord($record);
                        $this->saveChange($schema, fn () => app(WorldRequestService::class)->changeTemplate($this->actor(), $record,
                            (int) $data['luanti_world_template_id'], (int) $data['revision']), 'Neue Spielinstanz wird vorbereitet');
                    }),
                Action::make('retryGame')->label('Spielvorbereitung erneut prüfen')
                    ->visible(fn (WorldRequest $record): bool => $record->approved_access !== null && Gate::forUser($this->actor())->allows('update', $record))
                    ->action(function (WorldRequest $record): void {
                        $this->guardRecord($record);
                        Gate::forUser($this->actor())->authorize('update', $record);
                        app(LuantiGameInstances::class)->retry($record);
                        $this->saved('Spielvorbereitung wird erneut geprüft');
                    }),
            ])->emptyStateHeading('Noch keine Weltanträge');
    }

    private function guardRecord(WorldRequest $request): void
    {
        $organization = $this->requestOrganization();
        abort_if($organization !== null && $request->organization_id !== $organization->id, 404);
    }

    private function decisionAction(string $name, string $label, bool $approve): Action
    {
        return Action::make($name)->label($label)->color($approve ? 'success' : 'danger')->requiresConfirmation()
            ->visible(fn (WorldRequest $record): bool => $this->isInternalPanel() && Gate::forUser($this->actor())->allows('decide', $record)
                && (! $approve || $record->world->luanti_server_id !== null || Gate::forUser($this->actor())->allows('create', LuantiServer::class)))
            ->modalDescription(fn (WorldRequest $record): string => $approve && $record->world->luanti_server_id === null
                ? 'Mit der Genehmigung wird eine eigene kostenpflichtige Hetzner-VM für diesen Antrag erstellt. Luanti, Bridge und die angeforderte Welt werden automatisch eingerichtet. Die VM bleibt bis zur Löschung durch einen Administrator bestehen.'
                : ($approve ? 'Gibt den beantragten Zeitraum frei.' : 'Lehnt diesen Weltantrag ab.'))
            ->fillForm(fn (WorldRequest $record): array => ['revision' => $record->revision])
            ->schema([Hidden::make('revision')->required()->rules(['integer', 'min:1']),
                TextInput::make('name')->label('VM-Name')->default(fn (): string => HetznerVirtualMachines::generatedName())
                    ->visible(fn (WorldRequest $record): bool => $approve && $record->world->luanti_server_id === null)
                    ->required(fn (WorldRequest $record): bool => $approve && $record->world->luanti_server_id === null),
                TextInput::make('server_type')->label('Servertyp')->default('cx23')
                    ->visible(fn (WorldRequest $record): bool => $approve && $record->world->luanti_server_id === null)
                    ->required(fn (WorldRequest $record): bool => $approve && $record->world->luanti_server_id === null),
                TextInput::make('location')->label('Standort')->default('nbg1')
                    ->visible(fn (WorldRequest $record): bool => $approve && $record->world->luanti_server_id === null)
                    ->required(fn (WorldRequest $record): bool => $approve && $record->world->luanti_server_id === null),
            ])
            ->action(function (WorldRequest $record, array $data, Schema $schema) use ($approve): void {
                abort_unless($this->isInternalPanel(), 403);
                $this->guardRecord($record);
                $this->saveChange($schema, fn () => app(WorldRequestService::class)->decide($this->actor(), $record, $approve, (int) $data['revision'], $data),
                    $approve ? 'Weltantrag genehmigt' : 'Weltantrag abgelehnt');
            });
    }

    private function saveChange(?Schema $schema, Closure $change, string $message): void
    {
        try {
            $change();
        } catch (ValidationException $exception) {
            Notification::make()->title('Weltantrag konnte nicht gespeichert werden')
                ->body(collect($exception->errors())->flatten()->first())->danger()->send();
            $errors = [];
            $statePath = $schema?->getStatePath();
            foreach ($exception->errors() as $field => $messages) {
                $errors[filled($statePath) ? $statePath.'.'.$field : $field] = $messages;
            }
            throw ValidationException::withMessages($errors);
        }
        $this->saved($message);
    }

    /** @return list<TextInput|Select|DatePicker|Hidden> */
    private function requestFields(bool $editing = false): array
    {
        return [
            TextInput::make('name')->label('Name')->required()->maxLength(255),
            Select::make('luanti_world_template_id')->label('Weltvorlage')->required()->searchable()
                ->helperText('Wähle eine Vorlage aus der Portal-Bibliothek. Nach der Genehmigung wird dein eigener Spielserver eingerichtet.')
                ->disabled(fn (?WorldRequest $record): bool => $record?->current_game_instance_id !== null)->dehydrated()
                ->options(fn (?WorldRequest $record): array => app(WorldRequestService::class)->worldOptions($this->actor(), $this->requestOrganization(), $record)),
            DatePicker::make('start_date')->label('Startdatum')->required()->native()->displayFormat('d.m.Y')->format('Y-m-d'),
            DatePicker::make('end_date')->label('Enddatum')->required()->native()->displayFormat('d.m.Y')->format('Y-m-d')->afterOrEqual('start_date'),
            TextInput::make('participant_count')->label('Teilnehmendenzahl')->required()->integer()->minValue(1)->maxValue(4294967295)
                ->helperText('Voraussichtliche Zahl der Teilnehmenden für die Serverplanung.'),
            Hidden::make('revision')->required($editing)->rules(['nullable', 'integer', 'min:1']),
        ];
    }
}
