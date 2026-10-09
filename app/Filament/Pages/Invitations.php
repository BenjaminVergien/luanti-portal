<?php

namespace App\Filament\Pages;

use App\Enums\InternalRole;
use App\Enums\MembershipRole;
use App\Models\Invitation;
use App\Services\PortalAccess;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Gate;

class Invitations extends AccessTablePage
{
    protected static ?string $title = 'Einladungen';

    protected static ?string $slug = 'einladungen';

    protected function getHeaderActions(): array
    {
        return [
            Action::make('invite')->label('Zur Einrichtung einladen')
                ->schema([$this->organizationField(), TextInput::make('email')->label('E-Mail-Adresse')->email()->required(), $this->membershipRoleField()])
                ->action(function (array $data): void {
                    app(PortalAccess::class)->invite($this->actor(), $data['email'], $this->organizationFromData($data), MembershipRole::from($data['membership_role']));
                    $this->saved('Einladung versendet');
                }),
            Action::make('inviteInternal')->label('Interne Rolle einladen')->visible(fn (): bool => $this->isInternalPanel() && $this->actor()->isGlobalAdmin())
                ->schema([TextInput::make('email')->label('E-Mail-Adresse')->email()->required(), Select::make('internal_role')->label('Interne Rolle')->options(InternalRole::options())->required()])
                ->action(function (array $data): void {
                    app(PortalAccess::class)->invite($this->actor(), $data['email'], internalRole: InternalRole::from($data['internal_role']));
                    $this->saved('Interne Einladung versendet');
                }),
        ];
    }

    public function table(Table $table): Table
    {
        return $table->query(function () {
            $query = $this->scopedQuery(Invitation::query()->with(['organization', 'inviter']));
            if (! $this->actor()->isGlobalAdmin()) {
                $query->whereNotNull('organization_id');
            }

            return $query;
        })->defaultSort('created_at', 'desc')->columns([
            TextColumn::make('email')->label('E-Mail')->searchable(),
            TextColumn::make('organization.name')->label('Einrichtung')->placeholder('Interne Rolle')->visible(fn (): bool => $this->isInternalPanel()),
            TextColumn::make('role_label')->label('Rolle')->getStateUsing(fn (Invitation $record): string => ($record->membership_role ?? $record->internal_role)->label()),
            TextColumn::make('status_label')->label('Status')->getStateUsing(fn (Invitation $record): string => $record->statusLabel()),
            TextColumn::make('inviter.name')->label('Eingeladen von'),
            TextColumn::make('expires_at')->label('Gültig bis')->dateTime('d.m.Y H:i'),
        ])->recordActions([
            Action::make('revoke')->label('Widerrufen')->color('danger')->requiresConfirmation()
                ->visible(fn (Invitation $record): bool => Gate::forUser($this->actor())->allows('revoke', $record))
                ->action(function (Invitation $record): void {
                    app(PortalAccess::class)->revokeInvitation($this->actor(), $record);
                    $this->saved('Einladung widerrufen');
                }),
        ])->emptyStateHeading('Keine Einladungen');
    }
}
