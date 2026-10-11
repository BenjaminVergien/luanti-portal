<?php

namespace App\Filament\Pages;

use App\Enums\WorldRequestStatus;
use App\Models\Organization;
use App\Models\WorldRequest;
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
        return $table->query(fn () => app(WorldRequestService::class)->query($this->actor(), $this->requestOrganization()))
            ->defaultSort('created_at', 'desc')->columns([
                TextColumn::make('organization.name')->label('Einrichtung')->searchable()->visible(fn (): bool => $this->isInternalPanel()),
                TextColumn::make('name')->label('Name')->searchable()->sortable(),
                TextColumn::make('world.name')->label('Welt')->searchable(),
                TextColumn::make('world.server.name')->label('Server'),
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
                Action::make('edit')->label('Bearbeiten')
                    ->visible(fn (WorldRequest $record): bool => Gate::forUser($this->actor())->allows('update', $record))
                    ->fillForm(fn (WorldRequest $record): array => [
                        ...$record->only(['name', 'luanti_world_id', 'participant_count', 'revision']),
                        'start_date' => $record->start_date->toDateString(), 'end_date' => $record->end_date->toDateString(),
                    ])
                    ->schema($this->requestFields(editing: true))
                    ->modalDescription('Änderungen an Welt oder Zeitraum erfordern bei genehmigten Anträgen eine erneute Freigabe.')
                    ->action(function (WorldRequest $record, array $data, Schema $schema): void {
                        $this->guardRecord($record);
                        $this->saveChange($schema, fn () => app(WorldRequestService::class)->update($this->actor(), $record, $data, (int) $data['revision']), 'Weltantrag gespeichert');
                    }),
                $this->decisionAction('approve', 'Genehmigen', true),
                $this->decisionAction('reject', 'Ablehnen', false),
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
            ->visible(fn (WorldRequest $record): bool => $this->isInternalPanel() && Gate::forUser($this->actor())->allows('decide', $record))
            ->fillForm(fn (WorldRequest $record): array => ['revision' => $record->revision])
            ->schema([Hidden::make('revision')->required()->rules(['integer', 'min:1'])])
            ->action(function (WorldRequest $record, array $data, Schema $schema) use ($approve): void {
                abort_unless($this->isInternalPanel(), 403);
                $this->guardRecord($record);
                $this->saveChange($schema, fn () => app(WorldRequestService::class)->decide($this->actor(), $record, $approve, (int) $data['revision']),
                    $approve ? 'Weltantrag genehmigt' : 'Weltantrag abgelehnt');
            });
    }

    private function saveChange(Schema $schema, Closure $change, string $message): void
    {
        try {
            $change();
        } catch (ValidationException $exception) {
            Notification::make()->title('Weltantrag konnte nicht gespeichert werden')
                ->body(collect($exception->errors())->flatten()->first())->danger()->send();
            $errors = [];
            foreach ($exception->errors() as $field => $messages) {
                $errors[$schema->getStatePath().'.'.$field] = $messages;
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
            Select::make('luanti_world_id')->label('Welt')->required()->searchable()
                ->options(fn (?WorldRequest $record): array => app(WorldRequestService::class)->worldOptions($this->actor(), $this->requestOrganization(), $record)),
            DatePicker::make('start_date')->label('Startdatum')->required()->native()->displayFormat('d.m.Y')->format('Y-m-d'),
            DatePicker::make('end_date')->label('Enddatum')->required()->native()->displayFormat('d.m.Y')->format('Y-m-d')->afterOrEqual('start_date'),
            TextInput::make('participant_count')->label('Teilnehmendenzahl')->required()->integer()->minValue(1)->maxValue(4294967295),
            Hidden::make('revision')->required($editing)->rules(['nullable', 'integer', 'min:1']),
        ];
    }
}
