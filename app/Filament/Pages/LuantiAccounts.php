<?php

namespace App\Filament\Pages;

use App\Enums\WorldRequestStatus;
use App\Models\LuantiAccount;
use App\Models\LuantiAccountAssignment;
use App\Models\LuantiAccountJob;
use App\Models\LuantiGameInstance;
use App\Models\Organization;
use App\Models\WorldRequest;
use App\Services\LuantiAccounts as LuantiAccountService;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

class LuantiAccounts extends AccessTablePage
{
    protected static ?string $title = 'Spielaccounts';

    protected static ?string $slug = 'weltantraege/{worldRequest}/spielaccounts';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.pages.luanti-accounts';

    #[Locked]
    public int $worldRequestId;

    /** @var array{accounts?: array<array-key, array{username?: string, password?: string, role?: string}>}|null */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        $actor = auth()->user()?->fresh();
        if ($actor === null || ! $actor->is_active || ! $actor->hasVerifiedEmail()) {
            return false;
        }
        $organization = Filament::getTenant();

        return match (Filament::getCurrentPanel()?->getId()) {
            'admin' => $actor->isInternal(),
            'portal' => $organization instanceof Organization && $actor->canAccessTenant($organization),
            default => false,
        };
    }

    public function mount(int $worldRequest): void
    {
        $this->worldRequestId = $worldRequest;
        $this->request();
        $this->form->fill();
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            Repeater::make('accounts')->label('Neue Spielaccounts')->minItems(1)->maxItems(100)->defaultItems(1)
                ->addActionLabel('Spielaccount hinzufügen')->reorderable(false)->columns(3)->schema([
                    TextInput::make('username')->label('Spielname')->required()->maxLength(20)
                        ->regex('/\A[A-Za-z0-9_-]{1,20}\z/')->helperText('1–20 Zeichen: Buchstaben, Ziffern, _ und -.'),
                    TextInput::make('password')->label('Startpasswort')->required()->password()->revealable()
                        ->autocomplete('new-password')->minLength(12)->maxLength(1024)->default(fn (): string => Str::password(20))
                        ->suffixAction(Action::make('generatePassword')->label('Passwort generieren')->icon('heroicon-o-arrow-path')
                            ->action(function (Set $set): void {
                                $set('password', Str::password(20));
                            })),
                    Select::make('role')->label('Rolle')->required()->default('participant')
                        ->options(['facilitator' => 'Fachkraft', 'participant' => 'Mitglied']),
                ]),
        ]);
    }

    public function save(): void
    {
        $request = $this->request();
        abort_unless($request->status === WorldRequestStatus::Approved, 403);
        $data = $this->form->getState();

        try {
            app(LuantiAccountService::class)->create($this->actor(), $request, array_values($data['accounts']));
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(collect($exception->errors())
                ->mapWithKeys(fn (array $messages, string $field): array => ['data.'.$field => $messages])->all());
        }

        $this->form->fill(['accounts' => []]);
        $this->resetTable();
        Notification::make()->title('Spielaccounts gespeichert; Übertragung vorbereitet')->success()->send();
    }

    public function retryTransfers(): void
    {
        $request = $this->request();
        app(LuantiAccountService::class)->retry($this->actor(), $request);
        $this->resetTable();
        Notification::make()->title('Übertragung erneut vorbereitet')->success()->send();
    }

    public function table(Table $table): Table
    {
        return $table->query(fn () => LuantiAccount::query()->where('world_request_id', $this->request()->id)->with('assignments.instance.server', 'worldRequest'))
            ->defaultSort('created_at', 'desc')->poll('10s')->columns([
                TextColumn::make('username')->label('Spielname')->searchable(),
                TextColumn::make('role')->label('Rolle')->formatStateUsing(fn (string $state): string => $state === 'facilitator' ? 'Fachkraft' : 'Mitglied'),
                TextColumn::make('access_status')->label('Zugang')->badge()
                    ->state(fn (LuantiAccount $record): string => app(LuantiAccountService::class)->status($record)),
                TextColumn::make('assignments_summary')->label('Übertragung und bisheriger Zugang')->wrap()->listWithLineBreaks()
                    ->state(fn (LuantiAccount $record): array => $record->assignments->map(fn (LuantiAccountAssignment $assignment): string => $this->assignmentSummary($assignment, $record->worldRequest->approved_access))->all()),
                TextColumn::make('initial_password_mask')->label('Startpasswort')->state('••••••••••••'),
            ])->recordActions([
                Action::make('correctRejected')->label('Korrigieren')->icon('heroicon-o-pencil')
                    ->visible(fn (LuantiAccount $record): bool => $this->request()->status === WorldRequestStatus::Approved
                        && app(LuantiAccountService::class)->canCorrect($record))
                    ->modalHeading('Abgelehnten Spielaccount korrigieren')
                    ->modalDescription('Der bisherige Spielname wurde von der Bridge ohne Anlage abgelehnt. Die korrigierten Daten werden mit einem neuen Auftrag übertragen.')
                    ->fillForm(fn (LuantiAccount $record): array => ['username' => $record->username, 'password' => Str::password(20)])
                    ->schema([
                        TextInput::make('username')->label('Spielname')->required()->maxLength(20)->regex('/\A[A-Za-z0-9_-]{1,20}\z/'),
                        TextInput::make('password')->label('Startpasswort')->required()->password()->revealable()->autocomplete('new-password')
                            ->minLength(12)->maxLength(1024),
                    ])
                    ->action(function (LuantiAccount $record, array $data, Schema $schema): void {
                        abort_unless($record->world_request_id === $this->request()->id, 404);
                        try {
                            app(LuantiAccountService::class)->correctRejected($this->actor(), $record, $data);
                        } catch (ValidationException $exception) {
                            throw ValidationException::withMessages(collect($exception->errors())
                                ->mapWithKeys(fn (array $messages, string $field): array => [$schema->getStatePath().'.'.$field => $messages])->all());
                        }
                        $this->resetTable();
                        Notification::make()->title('Spielaccount korrigiert; Übertragung vorbereitet')->success()->send();
                    }),
                Action::make('revealPassword')->label('Startpasswort anzeigen')->icon('heroicon-o-key')
                    ->modal()->modalHeading('Gespeichertes Startpasswort')->modalSubmitAction(false)->modalCancelActionLabel('Schließen')
                    ->modalDescription('Spieler*innen können ihr Passwort im Spiel geändert haben. Dieses Startpasswort ist dann möglicherweise nicht mehr gültig.')
                    ->modalContent(fn (LuantiAccount $record): HtmlString => $this->passwordContent($record)),
            ])->emptyStateHeading('Noch keine Spielaccounts');
    }

    public function getBreadcrumbs(): array
    {
        return [WorldRequests::getUrl(panel: Filament::getCurrentPanel()->getId(), tenant: Filament::getTenant()) => 'Weltanträge', $this->getTitle()];
    }

    protected function getHeaderActions(): array
    {
        return [Action::make('retryTransfers')->label('Übertragung prüfen / erneut versuchen')->color('gray')
            ->action(function (): void {
                $this->retryTransfers();
            })];
    }

    protected function getViewData(): array
    {
        $request = $this->request();

        return ['worldRequest' => $request,
            'approvedWorld' => $request->approved_access === null ? null : LuantiGameInstance::query()->with('server')->find($request->approved_access['instance_id']),
            'approvedWindow' => $request->approved_access === null ? null : $this->formatWindow($request->approved_access),
            'recentJobs' => LuantiAccountJob::query()->where('world_request_id', $request->id)
                ->select(['id', 'world_request_id', 'luanti_game_instance_id', 'job_id', 'operation', 'state', 'error', 'created_at'])
                ->with('instance.server')->latest('id')->limit(20)->get()->map(fn (LuantiAccountJob $job): array => [
                    'id' => $job->job_id,
                    'world' => $job->instance?->instance_identifier ?? 'Spielinstanz nicht verfügbar',
                    'server' => $job->instance?->server?->name ?? 'Server nicht verfügbar',
                    'operation' => $job->operation === 'revoke' ? 'Sperrung' : 'Freigabe',
                    'state' => match ($job->state) {
                        'queued' => 'Für Übertragung vorbereitet', 'pending' => 'Wartet auf Bridge-Bestätigung',
                        'unknown' => 'Ausgang unbekannt – neue Freigaben warten auf Klärung',
                        'completed' => $job->error === null ? 'Auswertung abgeschlossen' : 'Fehlgeschlagen',
                        default => 'Wartet auf Prüfung',
                    },
                    'error' => $job->error,
                    'created_at' => $job->created_at->timezone('Europe/Berlin')->format('d.m.Y H:i'),
                ])->all()];
    }

    private function request(): WorldRequest
    {
        abort_unless(static::canAccess(), 403);
        $request = WorldRequest::query()->with('world.server', 'currentGameInstance.server', 'organization', 'facilitatorAccount')->findOrFail($this->worldRequestId);
        abort_if(! $this->isInternalPanel() && $request->organization_id !== Filament::getTenant()->id, 404);
        abort_unless($request->status === WorldRequestStatus::Approved || $request->approved_access !== null
            || LuantiAccount::query()->where('world_request_id', $request->id)->exists(), 404);
        Gate::forUser($this->actor())->authorize('manageAccounts', $request);

        return $request;
    }

    /** @param array{instance_id: int, starts_at: int, ends_at: int, generation: int}|null $approvedAccess */
    private function assignmentSummary(LuantiAccountAssignment $assignment, ?array $approvedAccess): string
    {
        $world = $assignment->instance?->instance_identifier ?? 'Spielinstanz nicht verfügbar';
        $server = $assignment->instance?->server?->name ?? 'Server nicht verfügbar';
        $state = $assignment->applied_window === null ? 'Übertragung wartet auf Bestätigung'
            : ($assignment->applied_admitted ? 'Bisher bestätigter Zugang: '.$this->formatWindow($assignment->applied_window) : 'Sperrung bestätigt');
        if ($assignment->outcome_uncertain) {
            $state = 'Technischer Fehler mit möglicherweise angewendeten Änderungen; Bestätigung ausstehend';
        }
        if ($assignment->applied_admitted && $assignment->applied_window !== null && $approvedAccess !== null && ($assignment->luanti_game_instance_id !== $approvedAccess['instance_id']
            || $assignment->applied_window['starts_at'] !== $approvedAccess['starts_at'] || $assignment->applied_window['ends_at'] !== $approvedAccess['ends_at'])) {
            $state = 'Sperrung ausstehend · '.$state;
        }

        return $world.' / '.$server.': '.$state.($assignment->error === null ? '' : ' · '.$assignment->error);
    }

    /** @param array{starts_at: int, ends_at: int} $window */
    private function formatWindow(array $window): string
    {
        $start = CarbonImmutable::createFromTimestamp($window['starts_at'], 'Europe/Berlin');
        $end = CarbonImmutable::createFromTimestamp($window['ends_at'], 'Europe/Berlin');

        return $start->format('d.m.Y H:i').' bis '.$end->format('d.m.Y H:i').' (Ende exklusiv, Europe/Berlin)';
    }

    private function passwordContent(LuantiAccount $account): HtmlString
    {
        $request = $this->request();
        abort_unless($account->world_request_id === $request->id, 404);
        if (! collect($this->mountedActions)->contains(fn (array $action): bool => $action['name'] === 'revealPassword')) {
            return new HtmlString('');
        }

        return new HtmlString('<div class="fi-prose"><code>'.e(app(LuantiAccountService::class)->revealPassword($this->actor(), $account)).'</code></div>');
    }
}
