<?php

namespace Tests\Feature;

use App\Filament\Pages\Settings as SettingsPage;
use App\Models\LuantiServer;
use App\Models\User;
use App\Services\HetznerCloudClient;
use App\Services\HetznerSettings as Settings;
use App\Services\LuantiImageCatalog;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class HetznerSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function adminPanel(User $user): void
    {
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::bootCurrentPanel();
        Filament::setTenant(null);
        config(['services.hetzner.token' => null]);
        Cache::forever(LuantiImageCatalog::CacheKey, ['images' => ['ghcr.io/luanti-org/luanti:5.17.0' => '5.17.0'], 'fetched_at' => now()->timestamp]);
    }

    public function test_admin_saves_token_encrypted_and_form_never_returns_the_saved_secret(): void
    {
        $this->adminPanel(User::factory()->globalAdmin()->create());
        Http::preventStrayRequests();

        $page = Livewire::test(SettingsPage::class)->fillForm(['api_token' => 'hetzner-secret'])
            ->call('save')->assertHasNoFormErrors()->assertNotified('Einstellungen gespeichert')->assertFormSet(['api_token' => null]);

        $ciphertext = DB::table('hetzner_settings')->where('id', 1)->value('api_token');
        $this->assertNotSame('hetzner-secret', $ciphertext);
        $this->assertSame('hetzner-secret', Crypt::decryptString($ciphertext));
        $payload = json_encode([$page->snapshot, $page->effects], JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('hetzner-secret', $payload);
        $this->assertStringNotContainsString($ciphertext, $payload);
        Livewire::test(SettingsPage::class)->assertFormSet(['api_token' => null])->assertSee('Token hinterlegt');
        Http::assertNothingSent();
    }

    public function test_blank_save_preserves_token_and_replacement_is_used_by_api_client(): void
    {
        $admin = User::factory()->globalAdmin()->create();
        $this->adminPanel($admin);
        app(Settings::class)->save($admin, ['api_token' => 'old-secret']);
        $ciphertext = DB::table('hetzner_settings')->value('api_token');

        $page = Livewire::test(SettingsPage::class)->fillForm(['api_token' => ''])->call('save')->assertHasNoFormErrors();

        $this->assertDatabaseHas('hetzner_settings', ['id' => 1, 'api_token' => $ciphertext]);
        $page->fillForm(['api_token' => 'replacement-secret'])->call('save')->assertHasNoFormErrors();
        Http::preventStrayRequests();
        Http::fake(['https://api.hetzner.cloud/v1/servers?*' => Http::response(['servers' => []])]);

        app(HetznerCloudClient::class)->request('GET', 'servers', ['per_page' => 1]);

        Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer replacement-secret'));
        $this->assertSame('replacement-secret', app(Settings::class)->token());
        $this->assertDatabaseCount('hetzner_settings', 1);
    }

    public function test_token_removal_requires_confirmation_and_disables_env_fallback_without_deleting_vms(): void
    {
        $admin = User::factory()->globalAdmin()->create();
        $this->adminPanel($admin);
        $server = LuantiServer::factory()->hetznerVm()->create();
        app(Settings::class)->save($admin, ['api_token' => 'saved-secret']);
        config(['services.hetzner.token' => 'environment-secret']);
        Http::preventStrayRequests();

        $page = Livewire::test(SettingsPage::class)->mountAction('removeToken');
        $this->assertSame('saved-secret', app(Settings::class)->token());
        $page->unmountAction();
        $this->assertSame('saved-secret', app(Settings::class)->token());
        $page->callAction('removeToken')->assertNotified('Hetzner-API-Token entfernt')->assertSee('Noch kein Token hinterlegt');

        $this->assertNull(app(Settings::class)->token());
        $this->assertDatabaseHas('hetzner_settings', ['id' => 1, 'api_token' => null]);
        $this->assertDatabaseHas('luanti_servers', ['id' => $server->id, 'hetzner_status' => 'running']);
        Http::assertNothingSent();
    }

    public function test_connection_check_uses_saved_token_without_creating_resources(): void
    {
        $admin = User::factory()->globalAdmin()->create();
        $this->adminPanel($admin);
        app(Settings::class)->save($admin, ['api_token' => 'saved-secret']);
        Http::preventStrayRequests();
        Http::fake(['https://api.hetzner.cloud/v1/servers?*' => Http::response(['servers' => []])]);

        Livewire::test(SettingsPage::class)->fillForm(['api_token' => 'unsaved-token'])
            ->callAction('checkConnection')->assertNotified('Hetzner-API erreichbar');

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && $request->hasHeader('Authorization', 'Bearer saved-secret') && $request['per_page'] === 1);
        $this->assertDatabaseCount('luanti_servers', 0);
    }

    /** @return array<string, array{int, mixed}> */
    public static function apiErrors(): array
    {
        return ['rejected token' => [401, ['error' => ['message' => 'private-details']]],
            'server error' => [500, 'private-details'], 'unexpected response' => [200, ['private-details']]];
    }

    #[DataProvider('apiErrors')]
    public function test_connection_check_sanitizes_provider_errors(int $status, mixed $body): void
    {
        $admin = User::factory()->globalAdmin()->create();
        $this->adminPanel($admin);
        app(Settings::class)->save($admin, ['api_token' => 'saved-secret']);
        Http::preventStrayRequests();
        Http::fake(['https://api.hetzner.cloud/v1/servers?*' => Http::response($body, $status)]);

        $page = Livewire::test(SettingsPage::class)->callAction('checkConnection')->assertNotified('Hetzner-API-Zugang nicht bestätigt');

        $payload = json_encode([$page->snapshot, $page->effects], JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('private-details', $payload);
        $this->assertStringNotContainsString('saved-secret', $payload);
        Http::assertSentCount(1);
    }

    public function test_connection_timeout_produces_sanitized_notification(): void
    {
        $admin = User::factory()->globalAdmin()->create();
        $this->adminPanel($admin);
        app(Settings::class)->save($admin, ['api_token' => 'saved-secret']);
        Http::preventStrayRequests();
        Http::fake(['https://api.hetzner.cloud/v1/servers?*' => Http::failedConnection('private-details')]);

        $page = Livewire::test(SettingsPage::class)->callAction('checkConnection')->assertNotified('Hetzner ist nicht erreichbar');

        $this->assertStringNotContainsString('private-details', json_encode([$page->snapshot, $page->effects], JSON_THROW_ON_ERROR));
        Http::assertSentCount(1);
    }

    public function test_general_settings_can_be_saved_without_a_token_and_connection_check_sends_no_request(): void
    {
        $this->adminPanel(User::factory()->globalAdmin()->create());
        Http::preventStrayRequests();

        Livewire::test(SettingsPage::class)->call('save')->assertHasNoFormErrors()
            ->callAction('checkConnection')->assertNotified('Noch kein Hetzner-Token hinterlegt');

        $this->assertDatabaseCount('hetzner_settings', 0);
        Http::assertNothingSent();
    }

    public function test_token_with_header_control_characters_is_rejected_without_overwriting_saved_token(): void
    {
        $admin = User::factory()->globalAdmin()->create();
        $this->adminPanel($admin);
        app(Settings::class)->save($admin, ['api_token' => 'saved-secret']);
        Http::preventStrayRequests();

        Livewire::test(SettingsPage::class)->fillForm(['api_token' => "token\r\nInjected: header"])
            ->call('save')->assertHasFormErrors(['api_token']);

        $this->assertSame('saved-secret', app(Settings::class)->token());
        Http::assertNothingSent();
    }

    public function test_guest_redirects_to_login_and_settings_only_exist_in_admin_panel(): void
    {
        $this->get('/admin/einstellungen')->assertRedirect('/admin/login');
        $this->assertNotContains(SettingsPage::class, Filament::getPanel('portal')->getPages());
    }

    /** @return array<string, array{string}> */
    public static function deniedActors(): array
    {
        return ['caseworker' => ['caseworker'], 'ordinary user' => ['ordinary'],
            'inactive admin' => ['inactive'], 'unverified admin' => ['unverified']];
    }

    #[DataProvider('deniedActors')]
    public function test_non_admins_cannot_open_settings(string $role): void
    {
        $actor = match ($role) {
            'caseworker' => User::factory()->caseworker()->create(),
            'inactive' => User::factory()->globalAdmin()->inactive()->create(),
            'unverified' => User::factory()->globalAdmin()->unverified()->create(), default => User::factory()->create(),
        };
        $this->adminPanel($actor);

        $this->get('/admin/einstellungen')->assertForbidden();
    }

    /** @return array<string, array{string}> */
    public static function settingsOperations(): array
    {
        return ['save' => ['save'], 'remove' => ['removeToken'], 'check' => ['checkConnection'], 'refresh' => ['refresh']];
    }

    #[DataProvider('settingsOperations')]
    public function test_open_page_refuses_operations_after_admin_is_demoted(string $operation): void
    {
        $admin = User::factory()->globalAdmin()->create();
        $this->adminPanel($admin);
        app(Settings::class)->save($admin, ['api_token' => 'saved-secret']);
        $page = Livewire::test(SettingsPage::class);
        $admin->forceFill(['internal_role' => null])->save();
        Http::preventStrayRequests();

        $response = match ($operation) {
            'save' => $page->call('save'), 'refresh' => $page->call('$refresh'),
            default => $page->call('mountAction', $operation),
        };
        $response->assertForbidden();

        $this->assertSame('saved-secret', app(Settings::class)->token());
        Http::assertNothingSent();
    }
}
