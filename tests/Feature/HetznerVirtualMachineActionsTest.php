<?php

namespace Tests\Feature;

use App\Enums\InternalRole;
use App\Filament\Pages\LuantiServers;
use App\Models\LuantiServer;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class HetznerVirtualMachineActionsTest extends TestCase
{
    use RefreshDatabase;

    private function adminPanel(User $user): void
    {
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::bootCurrentPanel();
        Filament::setTenant(null);
        config(['services.hetzner.token' => 'project-secret']);
    }

    public function test_vm_creation_dialog_creates_vm_without_game_server_fields(): void
    {
        $this->adminPanel(User::factory()->globalAdmin()->create());
        Http::preventStrayRequests();
        Http::fake([
            'https://api.hetzner.cloud/v1/ssh_keys?*' => Http::response(['ssh_keys' => []]),
            'https://api.hetzner.cloud/v1/ssh_keys' => fn (Request $request) => Http::response(['ssh_key' => [
                'id' => 12, 'name' => $request['name'], 'public_key' => $request['public_key'],
            ]], 201),
            'https://api.hetzner.cloud/v1/servers' => fn (Request $request) => Http::response(['server' => [
                'id' => 42, 'status' => 'initializing', 'labels' => $request['labels'],
                'public_net' => ['ipv4' => ['ip' => '192.0.2.42'], 'ipv6' => null],
            ]], 201)]);

        $page = Livewire::test(LuantiServers::class)->callAction('createVm', [
            'name' => ' Test-2.0 ', 'server_type' => 'cx23', 'location' => 'nbg1', 'image' => 'ubuntu-24.04',
        ])->assertHasNoActionErrors()->assertNotified('Wird erstellt');

        $this->assertDatabaseHas('luanti_servers', ['name' => 'test-2.0', 'domain' => null, 'hetzner_server_id' => 42]);
        $this->assertStringNotContainsString('project-secret', json_encode([$page->snapshot, $page->effects], JSON_THROW_ON_ERROR));
        $server = LuantiServer::query()->sole();
        $this->assertStringNotContainsString($server->provisioning_private_key, json_encode([$page->snapshot, $page->effects], JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString($server->getRawOriginal('provisioning_private_key'), json_encode([$page->snapshot, $page->effects], JSON_THROW_ON_ERROR));
        Http::assertSentCount(3);
    }

    public function test_invalid_vm_name_displays_inline_error_and_hover_help_before_any_request(): void
    {
        $this->adminPanel(User::factory()->globalAdmin()->create());
        Http::preventStrayRequests();

        Livewire::test(LuantiServers::class)->mountAction('createVm')
            ->assertMountedActionModalSee('2–253 Zeichen insgesamt')
            ->fillForm(['name' => 'test_2.0', 'server_type' => 'cx23', 'location' => 'nbg1', 'image' => 'ubuntu-24.04'])->callMountedAction()
            ->assertHasActionErrors(['name' => 'regex'])
            ->assertMountedActionModalSee('Ungültiger VM-Name');

        Http::assertNothingSent();
        $this->assertDatabaseCount('luanti_servers', 0);
    }

    public function test_backend_validation_errors_are_attached_to_the_open_vm_dialog(): void
    {
        $this->adminPanel(User::factory()->globalAdmin()->create());
        Http::preventStrayRequests();

        Livewire::test(LuantiServers::class)->callAction('createVm', [
            'name' => 'test-2.0', 'server_type' => 'invalid/type', 'location' => 'nbg1', 'image' => 'ubuntu-24.04',
        ])->assertHasActionErrors(['server_type'])->assertNotified('VM-Eingaben prüfen');

        Http::assertNothingSent();
        $this->assertDatabaseCount('luanti_servers', 0);
    }

    public function test_vm_deletion_requires_confirmation_and_cancellation_sends_no_request(): void
    {
        $this->adminPanel(User::factory()->globalAdmin()->create());
        $server = LuantiServer::factory()->hetznerVm()->create();
        Http::preventStrayRequests();

        Livewire::test(LuantiServers::class)->mountAction(TestAction::make('deleteVm')->table($server))
            ->assertMountedActionModalSee('Hetzner-VM unwiderruflich löschen?')->unmountAction();

        Http::assertNothingSent();
        $this->assertDatabaseHas('luanti_servers', ['id' => $server->id, 'hetzner_status' => 'running']);
    }

    public function test_vm_deletion_and_refresh_actions_are_wired_to_hetzner_and_preserve_local_record(): void
    {
        $this->adminPanel(User::factory()->globalAdmin()->create());
        $server = LuantiServer::factory()->hetznerVm()->create();
        Http::preventStrayRequests();
        Http::fake(['https://api.hetzner.cloud/v1/servers/42' => Http::sequence()->push(['server' => [
            'id' => 42, 'status' => 'running', 'labels' => ['managed-by' => 'luanti-portal', 'portal-reference' => $server->hetzner_reference],
        ]])->push(['action' => ['status' => 'running']])->push([], 404)]);

        $page = Livewire::test(LuantiServers::class)->assertActionHidden(TestAction::make('delete')->table($server))
            ->callAction(TestAction::make('deleteVm')->table($server), ['confirmation_name' => $server->name])->assertNotified('Wird gelöscht');
        $this->assertDatabaseHas('luanti_servers', ['id' => $server->id, 'hetzner_status' => 'deleting']);

        $page->callAction(TestAction::make('refreshVm')->table($server))->assertNotified('Gelöscht')
            ->assertActionVisible(TestAction::make('delete')->table($server))
            ->assertActionHidden(TestAction::make('deleteVm')->table($server));

        $this->assertDatabaseHas('luanti_servers', ['id' => $server->id, 'hetzner_status' => 'deleted']);
        Http::assertSentCount(3);
    }

    public function test_vm_deletion_dialog_requires_the_exact_name_and_explains_world_data_loss(): void
    {
        $this->adminPanel(User::factory()->globalAdmin()->create());
        $server = LuantiServer::factory()->hetznerVm()->create(['name' => 'happy-grove']);
        Http::preventStrayRequests();

        $page = Livewire::test(LuantiServers::class)->mountAction(TestAction::make('deleteVm')->table($server))
            ->assertMountedActionModalSee('Spielfortschritte')
            ->assertMountedActionModalSee('Es wird keine Sicherung erstellt.')
            ->assertMountedActionModalSee('happy-grove');
        $page->callMountedAction()->assertHasActionErrors(['confirmation_name' => 'required']);
        $page->fillForm(['confirmation_name' => 'another-server'])->callMountedAction()
            ->assertHasActionErrors(['confirmation_name' => 'in']);
        $page->fillForm(['confirmation_name' => 'HAPPY-GROVE'])->callMountedAction()
            ->assertHasActionErrors(['confirmation_name' => 'in']);

        Http::assertNothingSent();
        $this->assertSame('running', $server->fresh()->hetzner_status);
    }

    public function test_vm_deletion_dialog_rechecks_the_name_after_the_server_is_renamed(): void
    {
        $this->adminPanel(User::factory()->globalAdmin()->create());
        $server = LuantiServer::factory()->hetznerVm()->create(['name' => 'happy-grove']);
        Http::preventStrayRequests();
        $page = Livewire::test(LuantiServers::class)->mountAction(TestAction::make('deleteVm')->table($server));
        $server->update(['name' => 'renamed-server']);

        $page->fillForm(['confirmation_name' => 'happy-grove'])->callMountedAction()
            ->assertHasActionErrors(['confirmation_name']);

        Http::assertNothingSent();
    }

    public function test_missing_token_shows_actionable_notification_without_saving_a_vm(): void
    {
        $this->adminPanel(User::factory()->globalAdmin()->create());
        config(['services.hetzner.token' => null]);
        Http::preventStrayRequests();

        Livewire::test(LuantiServers::class)->callAction('createVm', [
            'name' => 'voxelgarden-test', 'server_type' => 'cx23', 'location' => 'nbg1', 'image' => 'ubuntu-24.04',
        ])->assertNotified('Hetzner-Anfrage nicht möglich');

        Http::assertNothingSent();
        $this->assertDatabaseCount('luanti_servers', 0);
    }

    public function test_manual_servers_do_not_offer_cloud_mutations(): void
    {
        $this->adminPanel(User::factory()->globalAdmin()->create());
        $server = LuantiServer::factory()->create();
        Http::preventStrayRequests();

        Livewire::test(LuantiServers::class)->assertActionHidden(TestAction::make('deleteVm')->table($server))
            ->assertActionHidden(TestAction::make('refreshVm')->table($server))
            ->assertActionVisible(TestAction::make('delete')->table($server));

        Http::assertNothingSent();
    }

    /** @return array<string, array{string}> */
    public static function vmActions(): array
    {
        return ['create' => ['createVm'], 'delete' => ['deleteVm'], 'refresh' => ['refreshVm'], 'resume' => ['retryKeySetup']];
    }

    #[DataProvider('vmActions')]
    public function test_open_page_refuses_cloud_actions_after_admin_access_is_revoked(string $action): void
    {
        $admin = User::factory()->globalAdmin()->create();
        $this->adminPanel($admin);
        $server = LuantiServer::factory()->hetznerVm()->create();
        $page = Livewire::test(LuantiServers::class);
        $admin->forceFill(['internal_role' => InternalRole::Caseworker])->save();
        Http::preventStrayRequests();

        $page->call('mountAction', $action, [], $action === 'createVm' ? [] : ['table' => true, 'recordKey' => $server->id])->assertForbidden();

        Http::assertNothingSent();
        $this->assertDatabaseCount('luanti_servers', 1);
    }
}
