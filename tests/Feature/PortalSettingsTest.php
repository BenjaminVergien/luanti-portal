<?php

namespace Tests\Feature;

use App\Filament\Pages\Settings;
use App\Models\User;
use App\Services\HetznerSettings;
use App\Services\LuantiImageCatalog;
use App\Services\PortalSettings;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PortalSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function adminPanel(User $user): void
    {
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::bootCurrentPanel();
        Filament::setTenant(null);
        config(['services.hetzner.token' => null]);
    }

    /** @param list<string> $tags */
    private function cacheImages(array $tags): void
    {
        $images = [];
        foreach ($tags as $tag) {
            $images['ghcr.io/luanti-org/luanti:'.$tag] = $tag;
        }
        Cache::forever(LuantiImageCatalog::CacheKey, ['images' => $images, 'fetched_at' => now()->timestamp]);
    }

    public function test_page_is_general_settings_and_loads_official_images_on_first_visit(): void
    {
        $this->adminPanel(User::factory()->globalAdmin()->create());
        Http::preventStrayRequests();
        Http::fake([
            'https://ghcr.io/token?*' => Http::response(['token' => 'registry-token']),
            'https://ghcr.io/v2/luanti-org/luanti/tags/list?*' => Http::response(['name' => 'luanti-org/luanti', 'tags' => ['5.17.0', 'latest']]),
        ]);

        Livewire::test(Settings::class)->assertOk()->assertSee('Einstellungen')->assertSee('Luanti-Docker-Image')
            ->assertFormSet(['luanti_docker_image' => null, 'api_token' => null]);

        $this->assertSame('5.17.0', app(LuantiImageCatalog::class)->images()['ghcr.io/luanti-org/luanti:5.17.0']);
        $this->assertDatabaseCount('portal_settings', 0);
        Http::assertSentCount(2);
    }

    public function test_admin_saves_image_without_hetzner_credentials_and_selection_survives_reopening(): void
    {
        $this->adminPanel(User::factory()->globalAdmin()->create());
        $this->cacheImages(['5.16.1', '5.17.0']);
        Http::preventStrayRequests();

        Livewire::test(Settings::class)->fillForm(['luanti_docker_image' => 'ghcr.io/luanti-org/luanti:5.16.1'])
            ->call('save')->assertHasNoFormErrors()->assertNotified('Einstellungen gespeichert');

        $this->assertSame('ghcr.io/luanti-org/luanti:5.16.1', app(PortalSettings::class)->luantiDockerImage());
        Livewire::test(Settings::class)->assertFormSet(['luanti_docker_image' => 'ghcr.io/luanti-org/luanti:5.16.1']);
        $this->assertDatabaseCount('hetzner_settings', 0);
        Http::assertNothingSent();
    }

    public function test_refresh_keeps_saved_image_even_when_its_tag_is_removed_and_does_not_select_new_version(): void
    {
        $admin = User::factory()->globalAdmin()->create();
        $this->adminPanel($admin);
        $this->cacheImages(['5.16.1']);
        app(PortalSettings::class)->save($admin, ['luanti_docker_image' => 'ghcr.io/luanti-org/luanti:5.16.1']);
        Http::preventStrayRequests();
        Http::fake([
            'https://ghcr.io/token?*' => Http::response(['token' => 'registry-token']),
            'https://ghcr.io/v2/luanti-org/luanti/tags/list?*' => Http::response(['name' => 'luanti-org/luanti', 'tags' => ['5.17.0']]),
        ]);

        Livewire::test(Settings::class)->callAction('refreshImages')->assertNotified('Image-Liste aktualisiert')
            ->assertFormSet(['luanti_docker_image' => 'ghcr.io/luanti-org/luanti:5.16.1'])
            ->call('save')->assertHasNoFormErrors();

        Livewire::test(Settings::class)->assertFormSet(['luanti_docker_image' => 'ghcr.io/luanti-org/luanti:5.16.1'])
            ->assertFormFieldExists('luanti_docker_image', fn (Select $field): bool => array_key_exists('ghcr.io/luanti-org/luanti:5.16.1', $field->getOptions()));
        $this->assertDatabaseHas('portal_settings', ['id' => 1, 'luanti_docker_image' => 'ghcr.io/luanti-org/luanti:5.16.1']);
        Http::assertSentCount(2);
    }

    public function test_refresh_failure_keeps_saved_and_unsaved_selection_and_does_not_leak_provider_errors(): void
    {
        $admin = User::factory()->globalAdmin()->create();
        $this->adminPanel($admin);
        $this->cacheImages(['5.16.1', '5.17.0']);
        app(PortalSettings::class)->save($admin, ['luanti_docker_image' => 'ghcr.io/luanti-org/luanti:5.16.1']);
        Http::preventStrayRequests();
        Http::fake(['https://ghcr.io/token?*' => Http::response('private-details', 500)]);

        $page = Livewire::test(Settings::class)->fillForm(['luanti_docker_image' => 'ghcr.io/luanti-org/luanti:5.17.0'])
            ->callAction('refreshImages')->assertNotified('Image-Liste nicht verfügbar')
            ->assertFormSet(['luanti_docker_image' => 'ghcr.io/luanti-org/luanti:5.17.0']);

        $this->assertStringNotContainsString('private-details', json_encode([$page->snapshot, $page->effects], JSON_THROW_ON_ERROR));
        $this->assertDatabaseHas('portal_settings', ['id' => 1, 'luanti_docker_image' => 'ghcr.io/luanti-org/luanti:5.16.1']);
        Http::assertSentCount(1);
    }

    public function test_startup_failure_still_shows_saved_image_and_allows_keeping_it(): void
    {
        $admin = User::factory()->globalAdmin()->create();
        $this->adminPanel($admin);
        $this->cacheImages(['5.16.1']);
        app(PortalSettings::class)->save($admin, ['luanti_docker_image' => 'ghcr.io/luanti-org/luanti:5.16.1']);
        Cache::forget(LuantiImageCatalog::CacheKey);
        Http::preventStrayRequests();
        Http::fake(['https://ghcr.io/token?*' => Http::failedConnection()]);

        Livewire::test(Settings::class)->assertNotified('Image-Liste nicht verfügbar')
            ->assertFormSet(['luanti_docker_image' => 'ghcr.io/luanti-org/luanti:5.16.1'])->call('save')->assertHasNoFormErrors();

        $this->assertDatabaseHas('portal_settings', ['id' => 1, 'luanti_docker_image' => 'ghcr.io/luanti-org/luanti:5.16.1']);
        Http::assertSentCount(1);
    }

    public function test_removing_hetzner_token_keeps_unsaved_image_choice(): void
    {
        $admin = User::factory()->globalAdmin()->create();
        $this->adminPanel($admin);
        $this->cacheImages(['5.17.0']);
        app(HetznerSettings::class)->save($admin, ['api_token' => 'stored-secret']);
        Http::preventStrayRequests();

        Livewire::test(Settings::class)->fillForm(['luanti_docker_image' => 'ghcr.io/luanti-org/luanti:5.17.0'])
            ->callAction('removeToken')->assertFormSet(['luanti_docker_image' => 'ghcr.io/luanti-org/luanti:5.17.0', 'api_token' => null]);

        $this->assertNull(app(HetznerSettings::class)->token());
        Http::assertNothingSent();
    }

    /** @return array<string, array{mixed}> */
    public static function invalidImages(): array
    {
        return ['unlisted tag' => ['ghcr.io/luanti-org/luanti:unknown'], 'other repository' => ['evil.example/image:latest'],
            'shell injection' => ['ghcr.io/luanti-org/luanti:latest;id'], 'html injection' => ['<script>alert(1)</script>']];
    }

    #[DataProvider('invalidImages')]
    public function test_invalid_image_does_not_change_image_or_token(mixed $image): void
    {
        $admin = User::factory()->globalAdmin()->create();
        $this->adminPanel($admin);
        $this->cacheImages(['5.17.0']);
        app(HetznerSettings::class)->save($admin, ['api_token' => 'stored-secret']);
        Http::preventStrayRequests();

        Livewire::test(Settings::class)->fillForm(['luanti_docker_image' => $image, 'api_token' => 'replacement-secret'])
            ->call('save')->assertHasFormErrors(['luanti_docker_image']);

        $this->assertDatabaseCount('portal_settings', 0);
        $this->assertSame('stored-secret', app(HetznerSettings::class)->token());
        Http::assertNothingSent();
    }

    public function test_general_settings_route_and_legacy_redirect_work(): void
    {
        $this->adminPanel(User::factory()->globalAdmin()->create());
        $this->cacheImages(['5.17.0']);
        Http::preventStrayRequests();

        $this->get('/admin/einstellungen')->assertOk()->assertSee('Einstellungen')->assertSee('Luanti-Docker-Image');
        $this->get('/admin/hetzner')->assertRedirectToRoute('filament.admin.pages.einstellungen');

        Http::assertNothingSent();
    }

    public function test_revoked_admin_cannot_save_or_refresh_images_on_open_settings_page(): void
    {
        $admin = User::factory()->globalAdmin()->create();
        $this->adminPanel($admin);
        $this->cacheImages(['5.17.0']);
        $page = Livewire::test(Settings::class);
        $admin->forceFill(['internal_role' => null])->save();
        Http::preventStrayRequests();

        $page->call('mountAction', 'refreshImages')->assertForbidden();

        $this->assertDatabaseCount('portal_settings', 0);
        Http::assertNothingSent();
    }
}
