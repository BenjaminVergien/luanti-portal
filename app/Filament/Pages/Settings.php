<?php

namespace App\Filament\Pages;

use App\Models\User;
use App\Services\GithubBridgeSource;
use App\Services\HetznerCloudClient;
use App\Services\HetznerDns;
use App\Services\HetznerSettings as HetznerConfiguration;
use App\Services\LuantiImageCatalog;
use App\Services\LuantiServerRegistry;
use App\Services\PortalAccess;
use App\Services\PortalSettings;
use App\Services\PublicEndpointPendingException;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
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

    /** @var array<string, mixed>|null */
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
            Section::make('Bridge aus GitHub')->description('Neue VMs erhalten automatisch den ausgewählten Bridge-Stand. Bestehende Server werden nicht aktualisiert.')
                ->schema([
                    Toggle::make('bridge_enabled')->label('Bridge bei neuen VMs automatisch installieren'),
                    TextInput::make('bridge_repository')->label('GitHub-Repository')->required()->maxLength(200)
                        ->helperText('Eigentümer/Repository, z. B. Stewie23/luanti-connect. Private Repositories werden unterstützt.'),
                    TextInput::make('bridge_ref')->label('Branch, Tag oder Commit')->required()->maxLength(200)
                        ->helperText('Standard: main. Pro Installation wird der genaue Commit gespeichert; spätere Branch-Änderungen ändern bestehende Server nicht.'),
                    TextEntry::make('github_token_status')->label('GitHub-Zugang')
                        ->state(fn (): string => filled(app(PortalSettings::class)->githubToken()) ? 'Token hinterlegt' : 'Kein Token hinterlegt – nur öffentliche Repositories'),
                    TextInput::make('bridge_github_token')->label('GitHub-Token')->password()->autocomplete('off')->maxLength(512)
                        ->helperText('Fine-grained Token nur für dieses Repository mit Contents: read-only. Wird verschlüsselt im Portal gespeichert und nicht an VMs übertragen. Leer lassen, um ihn beizubehalten.'),
                    TextInput::make('bridge_game_source')->label('Minetest-Game-Verzeichnis auf dem Portal-Host')->maxLength(2048)
                        ->helperText('Vorhandene, geprüfte Spieldateien mit game.conf. Die Dateien werden übertragen; Spiele und Weltvorlagen werden nicht automatisch heruntergeladen.'),
                    TextInput::make('bridge_admin_name')->label('Luanti-Administratorname')->required()->maxLength(20),
                ]),
            Section::make('Öffentliches HTTPS für neue VMs')
                ->description('Eine eigene API-Adresse pro neuer VM. Bestehende Server behalten ihre Verbindung. DNS-Token im Hetzner-Projekt KonsoleH erstellen; er bleibt ausschließlich im Portal.')
                ->schema([
                    Toggle::make('public_https_enabled')->label('DNS und HTTPS für neue VMs automatisch einrichten'),
                    TextInput::make('dns_zone')->label('DNS-Zone')->required()->maxLength(253)->rules(LuantiServerRegistry::domainRules()),
                    TextEntry::make('dns_token_status')->label('DNS-Zugang')
                        ->state(fn (): string => filled(app(PortalSettings::class)->dnsToken()) ? 'Token hinterlegt' : 'Noch kein DNS-Token hinterlegt'),
                    TextInput::make('dns_api_token')->label('DNS-API-Token (Projekt KonsoleH)')->password()->autocomplete('off')->maxLength(256)
                        ->helperText('Read-&-Write-Token. Wird verschlüsselt gespeichert und nie wieder angezeigt. Leer lassen, um den gespeicherten Token beizubehalten.'),
                    TextInput::make('acme_email')->label('E-Mail für Zertifikate')->email()->maxLength(254)
                        ->helperText('Kontaktadresse für Let’s Encrypt. Neue VMs benötigen die automatische Bridge-Installation sowie öffentlich erreichbare Ports 80 und 443.'),
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
            Action::make('checkDns')->label('Gespeicherten DNS-Zugang prüfen')
                ->action(function (): void {
                    $this->actor();
                    abort_unless(static::canAccess(), 403);
                    try {
                        $zone = app(HetznerDns::class)->zone();
                        Notification::make()->title('DNS-Zone erreichbar')->body($zone['name'].' ist als primäre Zone verfügbar. Für Änderungen ist ein Read-&-Write-Token nötig.')->success()->send();
                    } catch (ValidationException|PublicEndpointPendingException $exception) {
                        Notification::make()->title('DNS-Zugang nicht bestätigt')
                            ->body($exception instanceof ValidationException ? collect($exception->errors())->flatten()->first() : $exception->getMessage())->danger()->send();
                    }
                }),
            Action::make('removeDnsToken')->label('DNS-Token entfernen')->color('danger')->requiresConfirmation()
                ->modalDescription('Deaktiviert HTTPS für neue VMs. Bestehende Zertifikate erneuern sich weiter; DNS-Einrichtung und Bereinigung benötigen wieder einen Token aus demselben Projekt.')
                ->visible(fn (): bool => filled(app(PortalSettings::class)->dnsToken()))
                ->action(function (): void {
                    abort_unless(static::canAccess(), 403);
                    app(PortalSettings::class)->removeDnsToken($this->actor());
                    $this->data['dns_api_token'] = null;
                    $this->data['public_https_enabled'] = false;
                    Notification::make()->title('DNS-Token entfernt')->success()->send();
                }),
            Action::make('checkBridgeSource')->label('Gespeicherten GitHub-Zugang prüfen')
                ->action(function (): void {
                    abort_unless(static::canAccess(), 403);
                    $settings = app(PortalSettings::class)->bridge();
                    try {
                        $source = app(GithubBridgeSource::class);
                        $commit = $source->resolve($settings['bridge_repository'], $settings['bridge_ref']);
                        $source->bundle($settings['bridge_repository'], $commit);
                    } catch (ValidationException $exception) {
                        Notification::make()->title('GitHub-Zugang nicht bestätigt')->body(collect($exception->errors())->flatten()->first())->danger()->send();

                        return;
                    }
                    Notification::make()->title('Bridge aus GitHub verfügbar')->body('Docker-Installer im Commit '.substr($commit, 0, 12).' gefunden.')->success()->send();
                }),
            Action::make('removeGithubToken')->label('GitHub-Token entfernen')->color('danger')->requiresConfirmation()
                ->visible(fn (): bool => filled(app(PortalSettings::class)->githubToken()))
                ->action(function (): void {
                    abort_unless(static::canAccess(), 403);
                    app(PortalSettings::class)->removeGithubToken($this->actor());
                    $this->data['bridge_github_token'] = null;
                    Notification::make()->title('GitHub-Token entfernt')->success()->send();
                }),
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
        $this->form->fill(['luanti_docker_image' => app(PortalSettings::class)->luantiDockerImage(),
            ...app(PortalSettings::class)->bridge(), ...app(PortalSettings::class)->publicHttps()]);
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
