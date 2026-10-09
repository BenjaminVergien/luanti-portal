<?php

namespace App\Filament\Pages;

use App\Enums\InternalRole;
use App\Models\User;
use App\Services\PortalAccess;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class Accounts extends AccessTablePage
{
    protected static ?string $title = 'Konten';

    protected static ?string $slug = 'konten';

    public static function canAccess(): bool
    {
        return auth()->user()?->fresh()?->isGlobalAdmin() ?? false;
    }

    public function table(Table $table): Table
    {
        return $table->query(User::query())->columns([
            TextColumn::make('name')->label('Name')->searchable(),
            TextColumn::make('email')->label('E-Mail')->searchable(),
            TextColumn::make('internal_role')->label('Interne Rolle')->formatStateUsing(fn (?InternalRole $state): string => $state?->label() ?? '–')->placeholder('–'),
            IconColumn::make('is_active')->label('Aktiv')->boolean(),
            IconColumn::make('email_verified_at')->label('E-Mail bestätigt')->getStateUsing(fn (User $record): bool => $record->hasVerifiedEmail())->boolean(),
        ])->recordActions([
            Action::make('access')->label('Berechtigungen bearbeiten')
                ->fillForm(fn (User $record): array => ['internal_role' => $record->internal_role?->value, 'is_active' => $record->is_active])
                ->schema([
                    Select::make('internal_role')->label('Interne Rolle')->options(InternalRole::options())->placeholder('Keine interne Rolle')
                        ->helperText('Neue oder höhere interne Rechte werden über eine persönliche Einladung vergeben.'),
                    Toggle::make('is_active')->label('Konto aktiv')->required(),
                ])->action(function (User $record, array $data): void {
                    app(PortalAccess::class)->updateAccount($this->actor(), $record, filled($data['internal_role'] ?? null) ? InternalRole::from($data['internal_role']) : null, (bool) $data['is_active']);
                    $this->saved();
                }),
        ]);
    }
}
