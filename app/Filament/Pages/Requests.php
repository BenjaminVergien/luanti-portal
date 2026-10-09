<?php

namespace App\Filament\Pages;

use App\Enums\JoinRequestStatus;
use App\Models\JoinRequest;
use App\Services\PortalAccess;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Gate;

class Requests extends AccessTablePage
{
    protected static ?string $title = 'Beitrittsanträge';

    protected static ?string $slug = 'beitrittsantraege';

    public function table(Table $table): Table
    {
        return $table->query(fn () => $this->scopedQuery(JoinRequest::query()->with(['organization', 'user', 'decisionMaker'])))
            ->defaultSort('created_at', 'desc')->columns([
                TextColumn::make('organization.name')->label('Einrichtung')->visible(fn (): bool => $this->isInternalPanel()),
                TextColumn::make('user.name')->label('Name'),
                TextColumn::make('user.email')->label('E-Mail'),
                TextColumn::make('status')->label('Status')->formatStateUsing(fn (JoinRequestStatus $state): string => $state->label()),
                TextColumn::make('created_at')->label('Beantragt')->dateTime('d.m.Y H:i'),
                TextColumn::make('decisionMaker.name')->label('Entschieden von')->placeholder('–'),
            ])->recordActions([
                Action::make('approve')->label('Aufnehmen')->color('success')->requiresConfirmation()
                    ->visible(fn (JoinRequest $record): bool => Gate::forUser($this->actor())->allows('decide', $record))
                    ->action(function (JoinRequest $record): void {
                        app(PortalAccess::class)->decideRequest($this->actor(), $record, true);
                        $this->saved('Fachkraft aufgenommen');
                    }),
                Action::make('reject')->label('Ablehnen')->color('danger')->requiresConfirmation()
                    ->visible(fn (JoinRequest $record): bool => Gate::forUser($this->actor())->allows('decide', $record))
                    ->action(function (JoinRequest $record): void {
                        app(PortalAccess::class)->decideRequest($this->actor(), $record, false);
                        $this->saved('Antrag abgelehnt');
                    }),
            ])->emptyStateHeading('Keine Beitrittsanträge');
    }
}
