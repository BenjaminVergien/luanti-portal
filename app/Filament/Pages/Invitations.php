<?php

namespace App\Filament\Pages;

use App\Enums\InternalRole;
use App\Enums\MembershipRole;
use App\Models\Invitation;
use App\Services\PortalAccess;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

class Invitations extends AccessTablePage
{
    protected static ?string $title = 'Einladungen';

    protected static ?string $slug = 'einladungen';

    protected function getHeaderActions(): array
    {
        return [
            Action::make('invite')->label('Zur Einrichtung einladen')
                ->schema([$this->organizationField(), TextInput::make('email')->label('E-Mail-Adresse')->email()->required(), $this->membershipRoleField()])
                ->action(function (array $data, Schema $schema): void {
                    $this->sendInvitation($schema, fn (): Invitation => app(PortalAccess::class)->invite($this->actor(), $data['email'], $this->organizationFromData($data), MembershipRole::from($data['membership_role'])), 'Einladung zum Versand übergeben');
                }),
            Action::make('inviteInternal')->label('Interne Rolle einladen')->visible(fn (): bool => $this->isInternalPanel() && $this->actor()->isGlobalAdmin())
                ->schema([TextInput::make('email')->label('E-Mail-Adresse')->email()->required(), Select::make('internal_role')->label('Interne Rolle')->options(InternalRole::options())->required()])
                ->action(function (array $data, Schema $schema): void {
                    $this->sendInvitation($schema, fn (): Invitation => app(PortalAccess::class)->invite($this->actor(), $data['email'], internalRole: InternalRole::from($data['internal_role'])), 'Interne Einladung zum Versand übergeben');
                }),
        ];
    }

    private function sendInvitation(Schema $schema, callable $send, string $successMessage): void
    {
        try {
            $send();
        } catch (ValidationException $exception) {
            $errors = [];
            foreach ($exception->errors() as $field => $messages) {
                $errors[$schema->getStatePath().'.'.$field] = $messages;
            }
            throw ValidationException::withMessages($errors);
        } catch (TransportExceptionInterface $exception) {
            Log::warning('Portal invitation mail submission failed.', ['exception_type' => $exception::class]);

            throw ValidationException::withMessages([
                $schema->getStatePath().'.email' => 'Die Einladung konnte nicht sicher zum Versand übergeben werden. Der Einladungslink wurde vorsorglich deaktiviert. Bitte versuche es später erneut. Besteht das Problem weiter, wende dich an die Portalverwaltung.',
            ]);
        }

        Notification::make()->title($successMessage)
            ->body('Die E-Mail wurde zum Versand angenommen. Das bestätigt noch nicht die Zustellung. Bitte auch den Spamordner prüfen.')
            ->success()->send();
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
