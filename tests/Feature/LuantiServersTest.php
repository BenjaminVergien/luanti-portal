<?php

namespace Tests\Feature;

use App\Enums\InternalRole;
use App\Filament\Pages\LuantiServers;
use App\Models\LuantiServer;
use App\Models\OrganizationMembership;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LuantiServersTest extends TestCase
{
    use RefreshDatabase;

    private function adminPanel(User $user): void
    {
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::bootCurrentPanel();
        Filament::setTenant(null);
    }

    /** @return array{name: string, domain: string, game_port: int} */
    private function serverData(): array
    {
        return ['name' => 'Neuer Server', 'domain' => 'alpha.example.test', 'game_port' => 30000];
    }

    public function test_guests_are_redirected_to_admin_login(): void
    {
        $this->get('/admin/server')->assertRedirect('/admin/login');
    }

    public function test_global_admin_sees_server_page_and_navigation_only_in_admin_panel(): void
    {
        $admin = User::factory()->globalAdmin()->create();

        $this->actingAs($admin)->get('/admin/server')->assertOk()->assertSee('Luanti-Server')->assertSee('Server anlegen');
        $this->get('/portal/einrichtung/1/server')->assertNotFound();

        $this->assertNotContains(LuantiServers::class, Filament::getPanel('portal')->getPages());
    }

    public function test_admin_lists_searches_and_sorts_servers_without_exposing_tokens(): void
    {
        $this->adminPanel(User::factory()->globalAdmin()->create());
        $first = LuantiServer::factory()->create(['name' => 'Alpha', 'domain' => 'alpha.example.test', 'api_token' => 'protected-secret']);
        $second = LuantiServer::factory()->create(['name' => 'Zulu', 'domain' => 'zulu.example.test']);

        $page = Livewire::test(LuantiServers::class)->assertOk()->assertCanSeeTableRecords([$first, $second])
            ->assertDontSee('protected-secret')->assertDontSee($first->getRawOriginal('api_token'))
            ->sortTable('name')->assertCanSeeTableRecords([$first, $second], inOrder: true)
            ->sortTable('domain', 'desc')->assertCanSeeTableRecords([$second, $first], inOrder: true);
        $page->searchTable('Alpha')->assertCanSeeTableRecords([$first])->assertCanNotSeeTableRecords([$second]);
    }

    public function test_admin_creates_server_with_optional_bridge_configuration_without_network_calls(): void
    {
        $this->adminPanel(User::factory()->globalAdmin()->create());
        Http::preventStrayRequests();

        Livewire::test(LuantiServers::class)->callAction('create', [
            ...$this->serverData(), 'notes' => 'Interne Notiz',
            'api_base_url' => 'https://bridge.example.test:8443/api/v1', 'api_token' => 'bridge-issued-token',
        ])->assertHasNoActionErrors()->assertNotified('Server angelegt')->assertDontSee('bridge-issued-token');

        $this->assertDatabaseHas('luanti_servers', [
            ...$this->serverData(), 'notes' => 'Interne Notiz', 'api_base_url' => 'https://bridge.example.test:8443/api/v1',
        ]);
        $this->assertSame('bridge-issued-token', LuantiServer::query()->sole()->api_token);
        Http::assertNothingSent();
    }

    public function test_server_details_show_diagnostics_without_exposing_saved_credentials(): void
    {
        $this->adminPanel(User::factory()->globalAdmin()->create());
        Http::preventStrayRequests();
        $server = LuantiServer::factory()->create([
            'notes' => 'Server im Rechenzentrum',
            'hetzner_error' => 'VM konnte nicht gestartet werden',
            'luanti_installation_error' => 'Engine nicht erreichbar',
            'bridge_error' => 'Bridge-Prüfung fehlgeschlagen',
            'api_token' => 'private-api-token',
            'provisioning_private_key' => 'private-ssh-key',
            'bridge_configuration' => ['api_token' => 'private-bridge-token'],
        ]);

        $page = Livewire::test(LuantiServers::class)->assertSee('Fehler · siehe Details')
            ->mountAction(TestAction::make('details')->table($server))
            ->assertMountedActionModalSee($server->domain)
            ->assertMountedActionModalSee('Server im Rechenzentrum')
            ->assertMountedActionModalSee('VM konnte nicht gestartet werden')
            ->assertMountedActionModalSee('Engine nicht erreichbar')
            ->assertMountedActionModalSee('Bridge-Prüfung fehlgeschlagen');

        $payload = json_encode([$page->snapshot, $page->effects], JSON_THROW_ON_ERROR);
        foreach (['private-api-token', 'private-ssh-key', 'private-bridge-token', $server->getRawOriginal('api_token')] as $secret) {
            $this->assertStringNotContainsString($secret, $payload);
        }
        Http::assertNothingSent();
    }

    public function test_create_form_rejects_missing_required_fields_and_invalid_configuration(): void
    {
        $this->adminPanel(User::factory()->globalAdmin()->create());

        $page = Livewire::test(LuantiServers::class)->callAction('create')
            ->assertHasActionErrors(['name' => 'required', 'domain' => 'required', 'game_port' => 'required']);
        $page->fillForm(['name' => 'Server', 'domain' => 'https://host.example.test', 'game_port' => 65536, 'api_base_url' => 'http://bridge.example.test'])
            ->callMountedAction()->assertHasActionErrors(['domain' => 'regex', 'game_port' => 'max', 'api_base_url' => 'url']);

        $this->assertDatabaseCount('luanti_servers', 0);
    }

    public function test_edit_form_does_not_prefill_token_and_blank_edit_preserves_it(): void
    {
        $this->adminPanel(User::factory()->globalAdmin()->create());
        $server = LuantiServer::factory()->create(['name' => 'Original', 'notes' => 'Notiz', 'api_token' => 'saved-secret']);
        $ciphertext = $server->getRawOriginal('api_token');

        $page = Livewire::test(LuantiServers::class)->mountAction(TestAction::make('edit')->table($server))
            ->assertActionDataSet(['name' => 'Original', 'notes' => 'Notiz', 'api_token' => null])
            ->assertMountedActionModalDontSee('saved-secret')->assertMountedActionModalDontSee($ciphertext);
        $payload = json_encode([$page->snapshot, $page->effects], JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('saved-secret', $payload);
        $this->assertStringNotContainsString($ciphertext, $payload);

        $page->fillForm(['name' => 'Geänderter Server', 'domain' => 'updated.example.test', 'api_token' => ''])
            ->callMountedAction()->assertHasNoActionErrors()->assertNotified('Gespeichert');

        $this->assertDatabaseHas('luanti_servers', ['id' => $server->id, 'name' => 'Geänderter Server', 'domain' => 'updated.example.test', 'api_token' => $ciphertext]);
    }

    public function test_edit_form_replaces_token_without_returning_it(): void
    {
        $this->adminPanel(User::factory()->globalAdmin()->create());
        $server = LuantiServer::factory()->create(['api_token' => 'old-secret']);

        Livewire::test(LuantiServers::class)->callAction(TestAction::make('edit')->table($server), ['api_token' => 'replacement-secret'])
            ->assertHasNoActionErrors()->assertDontSee('replacement-secret');

        $this->assertSame('replacement-secret', $server->fresh()->api_token);
    }

    public function test_token_removal_requires_confirmation_and_can_be_cancelled(): void
    {
        $this->adminPanel(User::factory()->globalAdmin()->create());
        $server = LuantiServer::factory()->create(['api_token' => 'saved-secret']);

        $page = Livewire::test(LuantiServers::class)->mountAction(TestAction::make('removeToken')->table($server))
            ->assertMountedActionModalSee('API-Token entfernen?')->assertMountedActionModalDontSee('saved-secret');
        $this->assertSame('saved-secret', $server->fresh()->api_token);
        $page->unmountAction();
        $this->assertSame('saved-secret', $server->fresh()->api_token);

        $page->callAction(TestAction::make('removeToken')->table($server))->assertNotified('API-Token entfernt')
            ->assertActionHidden(TestAction::make('removeToken')->table($server));

        $this->assertDatabaseHas('luanti_servers', ['id' => $server->id, 'api_token' => null]);
    }

    public function test_server_deletion_requires_confirmation_and_can_be_cancelled(): void
    {
        $this->adminPanel(User::factory()->globalAdmin()->create());
        $server = LuantiServer::factory()->create();
        $other = LuantiServer::factory()->create();

        $page = Livewire::test(LuantiServers::class)->mountAction(TestAction::make('delete')->table($server))->assertMountedActionModalSee('Server löschen?');
        $this->assertModelExists($server);
        $page->unmountAction();
        $this->assertModelExists($server);

        $page->callAction(TestAction::make('delete')->table($server))->assertNotified('Server gelöscht');

        $this->assertModelMissing($server);
        $this->assertModelExists($other);
    }

    public static function deniedRoles(): array
    {
        return [
            'caseworker' => ['caseworker'], 'organization manager' => ['manager'], 'ordinary user' => ['ordinary'],
            'inactive admin' => ['inactive'], 'unverified admin' => ['unverified'],
        ];
    }

    #[DataProvider('deniedRoles')]
    public function test_non_admins_cannot_open_server_page_or_see_its_navigation(string $role): void
    {
        $actor = match ($role) {
            'caseworker' => User::factory()->caseworker()->create(),
            'inactive' => User::factory()->globalAdmin()->inactive()->create(),
            'unverified' => User::factory()->globalAdmin()->unverified()->create(),
            default => User::factory()->create(),
        };
        if ($role === 'manager') {
            OrganizationMembership::factory()->manager()->create(['user_id' => $actor->id]);
        }
        $this->adminPanel($actor);

        $this->get('/admin/server')->assertForbidden();

        foreach (Filament::getPanel('admin')->getNavigation() as $group) {
            foreach ($group->getItems() as $item) {
                $this->assertNotSame('Luanti-Server', $item->getLabel());
            }
        }
    }

    public static function revokedPermissions(): array
    {
        $cases = [];
        foreach (['demoted', 'role removed', 'inactive', 'unverified'] as $change) {
            foreach (['refresh', 'create', 'edit', 'removeToken', 'delete'] as $operation) {
                $cases[$change.' during '.$operation] = [$change, $operation];
            }
        }

        return $cases;
    }

    #[DataProvider('revokedPermissions')]
    public function test_open_page_refuses_reads_and_mutations_after_admin_access_is_revoked(string $change, string $operation): void
    {
        $admin = User::factory()->globalAdmin()->create();
        $this->adminPanel($admin);
        $server = LuantiServer::factory()->create(['api_token' => 'saved-secret']);
        $originalAttributes = $server->fresh()->getRawOriginal();
        $page = Livewire::test(LuantiServers::class);
        if ($operation !== 'refresh') {
            $action = $operation === 'create' ? TestAction::make('create') : TestAction::make($operation)->table($server);
            $page->mountAction($action);
            if (in_array($operation, ['create', 'edit'], true)) {
                $page->fillForm($this->serverData());
            }
        }
        $admin->forceFill(match ($change) {
            'demoted' => ['internal_role' => InternalRole::Caseworker],
            'role removed' => ['internal_role' => null],
            'inactive' => ['is_active' => false],
            'unverified' => ['email_verified_at' => null],
        })->save();

        $page->call($operation === 'refresh' ? '$refresh' : 'callMountedAction')->assertForbidden();

        $this->assertDatabaseCount('luanti_servers', 1);
        $this->assertSame($originalAttributes, $server->fresh()->getRawOriginal());
    }

    public function test_server_names_are_escaped_and_unexpected_fields_are_ignored(): void
    {
        $this->adminPanel(User::factory()->globalAdmin()->create());

        Livewire::test(LuantiServers::class)->callAction('create', [
            ...$this->serverData(), 'name' => '<script>alert("server")</script>', 'id' => 999,
        ])->assertHasNoActionErrors()->assertSee('&lt;script&gt;', escape: false)
            ->assertDontSee('<script>alert("server")</script>', escape: false);

        $this->assertDatabaseMissing('luanti_servers', ['id' => 999]);
        $this->assertDatabaseHas('luanti_servers', ['name' => '<script>alert("server")</script>']);
    }
}
