<?php

namespace App\Filament\Pages;

use App\Enums\MembershipRole;
use App\Models\OrganizationMembership;
use App\Services\PortalAccess;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Gate;

class Members extends AccessTablePage
{
    protected static ?string $title = 'Mitgliedschaften';

    protected static ?string $slug = 'mitgliedschaften';

    protected function getHeaderActions(): array
    {
        return [Action::make('assign')->label(fn (): string => $this->isInternalPanel() ? 'Mitgliedschaft zuordnen' : 'Fachkraft zuordnen')
            ->schema([$this->organizationField(), TextInput::make('email')->label('Bestätigte E-Mail-Adresse')->email()->required(), $this->membershipRoleField()])
            ->action(function (array $data): void {
                app(PortalAccess::class)->assignByEmail($this->actor(), $this->organizationFromData($data), $data['email'], MembershipRole::from($data['membership_role']));
                $this->saved('Mitgliedschaft gespeichert');
            })];
    }

    public function table(Table $table): Table
    {
        return $table->query(fn () => $this->scopedQuery(OrganizationMembership::query()->with(['organization', 'user'])))
            ->columns([
                TextColumn::make('organization.name')->label('Einrichtung')->visible(fn (): bool => $this->isInternalPanel())->searchable(),
                TextColumn::make('user.name')->label('Name')->searchable(),
                TextColumn::make('user.email')->label('E-Mail')->searchable(),
                TextColumn::make('role')->label('Rolle')->formatStateUsing(fn (MembershipRole $state): string => $state->label()),
            ])->recordActions([
                Action::make('role')->label('Rolle ändern')->visible(fn (): bool => $this->actor()->isInternal())
                    ->fillForm(fn (OrganizationMembership $record): array => ['membership_role' => $record->role->value])
                    ->schema([$this->membershipRoleField()])
                    ->action(function (OrganizationMembership $record, array $data): void {
                        app(PortalAccess::class)->assignMembership($this->actor(), $record->organization, $record->user, MembershipRole::from($data['membership_role']));
                        $this->saved();
                    }),
                Action::make('remove')->label('Entfernen')->color('danger')->requiresConfirmation()
                    ->modalDescription('Die Person verliert den Zugriff auf diese Einrichtung. Andere Mitgliedschaften bleiben erhalten.')
                    ->visible(fn (OrganizationMembership $record): bool => Gate::forUser($this->actor())->allows('delete', $record))
                    ->action(function (OrganizationMembership $record): void {
                        app(PortalAccess::class)->removeMembership($this->actor(), $record);
                        $this->saved('Mitgliedschaft entfernt');
                    }),
            ])->emptyStateHeading('Keine Mitgliedschaften');
    }
}
