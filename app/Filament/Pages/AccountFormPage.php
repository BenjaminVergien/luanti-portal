<?php

namespace App\Filament\Pages;

use App\Enums\InternalRole;
use App\Models\User;
use App\Services\PortalAccess;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

abstract class AccountFormPage extends Page
{
    protected static bool $shouldRegisterNavigation = false;

    /** @var array<string, mixed> */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return Filament::getCurrentPanel()?->getId() === 'admin' && (auth()->user()?->fresh()?->isGlobalAdmin() ?? false);
    }

    public function form(Schema $schema): Schema
    {
        $account = $this->account();
        $editing = $account !== null;

        return $schema->statePath('data')->components([
            Section::make('Kontodaten')->schema([
                TextInput::make('name')->label('Name')->required()->maxLength(255),
                TextInput::make('email')->label('E-Mail')->required()->email()->maxLength(255)
                    ->mutateStateForValidationUsing(fn (?string $state): ?string => $state === null ? null : Str::lower(trim($state)))
                    ->dehydrateStateUsing(fn (?string $state): ?string => $state === null ? null : Str::lower(trim($state)))
                    ->rules([Rule::unique(User::class, 'email')->ignore($account?->id)])
                    ->live(onBlur: true)->afterStateUpdated(function (?string $state, Set $set) use ($account): void {
                        if (Str::lower(trim($state ?? '')) !== $account?->email) {
                            $set('email_verified', false);
                        }
                    }),
                Toggle::make('is_active')->label('Konto aktiv')->default(true)->required(),
                Toggle::make('email_verified')->label('E-Mail bestätigt')->default(false)->required()
                    ->helperText('Normalerweise bestätigt die Person ihre E-Mail-Adresse selbst. Die manuelle Bestätigung ist eine administrative Ausweichmöglichkeit. Eine geänderte Adresse muss erneut bestätigt werden. Interne Rollen benötigen eine bestätigte E-Mail-Adresse; zum Aufheben zuerst die interne Rolle entfernen. Der letzte aktive Globaladmin muss erhalten bleiben.'),
                Select::make('internal_role')->label('Interne Rolle')->options(InternalRole::options())
                    ->placeholder('Keine interne Rolle')->visible($editing)->dehydrated($editing)
                    ->helperText('Neue oder höhere interne Rechte werden über eine persönliche Einladung vergeben.'),
            ]),
            Section::make('Passwort')->schema([
                TextInput::make('password')->label($editing ? 'Neues Passwort' : 'Passwort')->password()->autocomplete('new-password')
                    ->required(! $editing)->minLength(12)->same('password_confirmation')
                    ->helperText($editing ? 'Leer lassen, um das bisherige Passwort beizubehalten. Mindestens 12 Zeichen.' : 'Mindestens 12 Zeichen.'),
                TextInput::make('password_confirmation')->label('Passwort wiederholen')->password()->autocomplete('new-password')
                    ->required(! $editing)->requiredWith('password'),
            ]),
            Section::make('Kontoinformationen')->visible($editing)->schema([
                TextEntry::make('account_id')->label('Konto-ID')->state(fn (): ?int => $this->account()?->id),
                TextEntry::make('created_at')->label('Angelegt am')->state(fn (): ?string => $this->account()?->created_at?->format('d.m.Y H:i')),
                TextEntry::make('email_verified_at')->label('E-Mail bestätigt am')->placeholder('Nicht bestätigt')
                    ->state(fn (): ?string => $this->account()?->email_verified_at?->format('d.m.Y H:i')),
            ]),
        ]);
    }

    public function save(): void
    {
        abort_unless(static::canAccess(), 403);
        $data = $this->form->getState();

        try {
            $this->persist($data);
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(collect($exception->errors())->mapWithKeys(fn (array $messages, string $field): array => ['data.'.$field => $messages])->all());
        }
    }

    public function getBreadcrumbs(): array
    {
        return [Accounts::getUrl(panel: 'admin') => 'Konten', $this->getTitle()];
    }

    protected function actor(): User
    {
        return app(PortalAccess::class)->currentUser(auth()->user());
    }

    protected function account(): ?User
    {
        return null;
    }

    /** @param array<string, mixed> $data */
    abstract protected function persist(#[\SensitiveParameter] array $data): void;
}
