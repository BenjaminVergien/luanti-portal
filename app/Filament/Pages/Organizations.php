<?php

namespace App\Filament\Pages;

use App\Models\Organization;
use App\Services\PortalAccess;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Gate;

class Organizations extends AccessTablePage
{
    protected static ?string $title = 'Einrichtungen';

    protected static ?string $slug = 'einrichtungen';

    public static function canAccess(): bool
    {
        return auth()->user()?->fresh()?->isInternal() ?? false;
    }

    protected function getHeaderActions(): array
    {
        return [Action::make('create')->label('Einrichtung anlegen')->visible(fn (): bool => $this->actor()->isGlobalAdmin())
            ->schema([TextInput::make('name')->label('Name')->required()->maxLength(255)])
            ->action(function (array $data): void {
                app(PortalAccess::class)->createOrganization($this->actor(), $data['name']);
                $this->saved('Einrichtung angelegt');
            })];
    }

    public function table(Table $table): Table
    {
        return $table->query(Organization::query()->with('verifiedBy'))->columns([
            TextColumn::make('name')->label('Name')->searchable()->sortable(),
            TextColumn::make('memberships_count')->counts('memberships')->label('Mitgliedschaften'),
            TextColumn::make('verified_at')->label('Verifiziert am')->dateTime('d.m.Y H:i')->placeholder('Nicht verifiziert')->sortable(),
            TextColumn::make('verifiedBy.name')->label('Verifiziert durch')->placeholder('–'),
        ])->recordActions([
            Action::make('rename')->label('Bearbeiten')->visible(fn (Organization $record): bool => Gate::forUser($this->actor())->allows('update', $record))
                ->fillForm(fn (Organization $record): array => ['name' => $record->name])
                ->schema([TextInput::make('name')->label('Name')->required()->maxLength(255)])
                ->action(function (Organization $record, array $data): void {
                    app(PortalAccess::class)->renameOrganization($this->actor(), $record, $data['name']);
                    $this->saved();
                }),
            Action::make('verify')->label('Einrichtung verifizieren')
                ->visible(fn (Organization $record): bool => $record->verified_at === null && Gate::forUser($this->actor())->allows('verify', $record))
                ->action(function (Organization $record): void {
                    app(PortalAccess::class)->verifyOrganization($this->actor(), $record);
                    $this->saved('Einrichtung verifiziert');
                }),
        ])->emptyStateHeading('Noch keine Einrichtungen');
    }
}
