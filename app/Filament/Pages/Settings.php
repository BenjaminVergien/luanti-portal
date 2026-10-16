<?php

namespace App\Filament\Pages;

use App\Models\User;
use App\Services\HetznerCloudClient;
use App\Services\HetznerSettings as HetznerConfiguration;
use App\Services\LuantiImageCatalog;
use App\Services\PortalAccess;
use App\Services\PortalSettings;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Validation\ValidationException;

class Settings extends Page
{
    protected static ?string $title = 'Einstellungen';

    protected static ?string $slug = 'einstellungen';

    protected string $view = 'filament.pages.settings';

    /** @var array{api_token?: ?string, luanti_docker_image?: ?string} */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return Filament::getCurrentPanel()?->getId() === 'admin' && (auth()->user()?->fresh()?->isGlobalAdmin() ?? false);
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        if (app(LuantiImageCatalog::class)->needsRefresh()) {
            $this->refreshImages(false);
        }
        $this->fillSettings();
    }

    public function hydrate(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            Section::make('Luanti')->description('Standard-Image aus dem offiziellen Luanti-Repository für die Serverinstallation.')
                ->schema([
                    Select::make('luanti_docker_image')->label('Luanti-Docker-Image')->searchable()
                        ->placeholder('Image auswählen')
                        ->options(fn (Get $get): array => $this->imageOptions($get('luanti_docker_image')))
                        ->helperText('Repository: ghcr.io/luanti-org/luanti. Die Auswahl bleibt gespeichert, bis du sie änderst. Bestehende Server werden dadurch nicht aktualisiert.'),
                ]),
            Section::make('Ein Hetzner-Projekt für das gesamte Portal')
                ->description('In der Hetzner Console das Projekt voxelgarden öffnen und unter Sicherheit → API-Tokens einen Read-&-Write-Token erstellen. Der Token bestimmt das Projekt; ein Projektname oder eine Projekt-ID wird nicht benötigt. Ersatztokens im selben Projekt erstellen, solange das Portal dort VMs verwaltet.')
                ->schema([
                    TextEntry::make('token_status')->label('API-Zugang')
                        ->state(fn (): string => app(HetznerConfiguration::class)->hasToken() ? 'Token hinterlegt' : 'Noch kein Token hinterlegt'),
                    TextEntry::make('provisioning_keys')->label('SSH-Zugang für VMs')
                        ->state('Wird automatisch pro VM eingerichtet')
                        ->helperText('Jede VM erhält einen eigenen Schlüssel. Das Portal speichert den privaten Schlüssel verschlüsselt, registriert den öffentlichen Schlüssel bei Hetzner und entfernt ihn beim Löschen der VM.'),
                    TextInput::make('api_token')->label('Hetzner-API-Token')->password()->autocomplete('off')->maxLength(256)
                        ->helperText('Wird verschlüsselt gespeichert und nie wieder angezeigt. Leer lassen, um den gespeicherten Token beizubehalten.'),
                ]),
        ]);
    }

    public function save(): void
    {
        abort_unless(static::canAccess(), 403);
        $data = $this->form->getState();
        try {
            app(PortalSettings::class)->save($this->actor(), $data);
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(collect($exception->errors())
                ->mapWithKeys(fn (array $messages, string $field): array => ['data.'.$field => $messages])->all());
        }
        $this->fillSettings();
        Notification::make()->title('Einstellungen gespeichert')->success()->send();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('refreshImages')->label('Image-Liste aktualisieren')
                ->action(fn () => $this->refreshImages()),
            Action::make('checkConnection')->label('Gespeicherten API-Zugang prüfen')
                ->action(function (): void {
                    $this->actor();
                    abort_unless(static::canAccess(), 403);
                    try {
                        $response = app(HetznerCloudClient::class)->request('GET', 'servers', ['per_page' => 1]);
                    } catch (ValidationException) {
                        Notification::make()->title('Noch kein Hetzner-Token hinterlegt')->danger()->send();

                        return;
                    } catch (ConnectionException) {
                        Notification::make()->title('Hetzner ist nicht erreichbar')->danger()->send();

                        return;
                    }
                    if (! $response->ok() || ! is_array($response->json('servers'))) {
                        Notification::make()->title('Hetzner-API-Zugang nicht bestätigt')
                            ->body('Bitte Token, Projekt und Netzwerk prüfen.')->danger()->send();

                        return;
                    }
                    Notification::make()->title('Hetzner-API erreichbar')
                        ->body('Der gespeicherte Token erlaubt Lesezugriff. Für VM-Erstellung und Löschung muss der Token Read-&-Write-Rechte besitzen.')
                        ->success()->send();
                }),
            Action::make('removeToken')->label('API-Token entfernen')->color('danger')->requiresConfirmation()
                ->modalDescription('Entfernt den API-Zugang aus dem Portal. Bestehende VMs laufen bei Hetzner weiter.')
                ->visible(fn (): bool => app(HetznerConfiguration::class)->hasToken())
                ->action(function (): void {
                    abort_unless(static::canAccess(), 403);
                    app(HetznerConfiguration::class)->removeToken($this->actor());
                    $this->data['api_token'] = null;
                    Notification::make()->title('Hetzner-API-Token entfernt')->success()->send();
                }),
        ];
    }

    private function actor(): User
    {
        return app(PortalAccess::class)->currentUser(auth()->user());
    }

    private function fillSettings(): void
    {
        $this->form->fill(['luanti_docker_image' => app(PortalSettings::class)->luantiDockerImage()]);
    }

    /** @return array<string, string> */
    private function imageOptions(?string $current): array
    {
        $images = app(LuantiImageCatalog::class)->images();
        foreach ([app(PortalSettings::class)->luantiDockerImage(), $current] as $image) {
            if (filled($image) && ! array_key_exists($image, $images)) {
                $images[$image] = $image.' (gespeichert/ausgewählt)';
            }
        }

        return $images;
    }

    private function refreshImages(bool $notifySuccess = true): void
    {
        abort_unless(static::canAccess(), 403);
        try {
            app(LuantiImageCatalog::class)->refresh($this->actor());
        } catch (ValidationException $exception) {
            Notification::make()->title('Image-Liste nicht verfügbar')->body($exception->errors()['luanti_docker_image'][0])->warning()->send();

            return;
        }
        if ($notifySuccess) {
            Notification::make()->title('Image-Liste aktualisiert')->success()->send();
        }
    }
}
