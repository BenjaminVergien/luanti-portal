<?php

namespace Tests\Feature;

use App\Enums\WorldRequestStatus;
use App\Filament\Pages\WorldRequests as WorldRequestsPage;
use App\Jobs\ProvisionLuantiBridge;
use App\Jobs\ProvisionWorldRequest;
use App\Models\LuantiServer;
use App\Models\LuantiWorldTemplate;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Models\WorldRequest;
use App\Services\BridgeProvisioning;
use App\Services\HetznerVirtualMachines;
use App\Services\LuantiAccounts;
use App\Services\LuantiServerRegistry;
use App\Services\PortalWorldTemplates;
use App\Services\WorldRequestProvisioning;
use App\Services\WorldRequests;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PDO;
use Tests\TestCase;
use ZipArchive;

class WorldRequestProvisioningTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private LuantiWorldTemplate $template;

    private OrganizationMembership $membership;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::preventStrayRequests();
        Storage::fake('local');
        config(['services.hetzner.token' => 'project-secret']);
        DB::table('portal_settings')->insert(['id' => 1, 'bridge_enabled' => true, 'luanti_docker_image' => 'ghcr.io/luanti-org/luanti:5.17.0']);
        $this->admin = User::factory()->globalAdmin()->create();
        $this->membership = OrganizationMembership::factory()->create();
        $database = Storage::disk('local')->path('fixture.sqlite');
        $connection = new PDO('sqlite:'.$database);
        $connection->exec('CREATE TABLE blocks (pos INTEGER PRIMARY KEY, data BLOB)');
        $connection = null;
        $files = ['world.mt' => "gameid = minetest\nload_mod_civicrm_bridge = mods/civicrm_bridge\n", 'map.sqlite' => file_get_contents($database)];
        $hashes = array_map(fn (string $value): string => hash('sha256', $value), $files);
        ksort($hashes);
        $content = ['files' => $hashes, 'name' => 'Dev', 'template_id' => 'dev'];
        $archive = Storage::disk('local')->path('fixture.zip');
        $zip = new ZipArchive;
        $zip->open($archive, ZipArchive::CREATE);
        foreach ([...$files, 'manifest.json' => json_encode(['version' => 1, ...$content,
            'revision' => hash('sha256', json_encode($content, JSON_UNESCAPED_SLASHES))])] as $name => $bytes) {
            $zip->addFromString($name, $bytes);
        }
        $zip->close();
        $this->template = app(PortalWorldTemplates::class)->import($this->admin, $archive);
    }

    private function submit(): WorldRequest
    {
        return app(WorldRequests::class)->create($this->membership->user, $this->membership->organization, [
            'name' => 'Jugendtreff', 'luanti_world_template_id' => $this->template->id,
            'start_date' => now('Europe/Berlin')->toDateString(), 'end_date' => now('Europe/Berlin')->addDays(7)->toDateString(),
            'participant_count' => 12,
        ]);
    }

    private function approve(WorldRequest $request): void
    {
        app(WorldRequests::class)->decide($this->admin, $request, true, 1, ['name' => 'happy-grove']);
    }

    private function panel(User $user, string $panel = 'admin'): void
    {
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel($panel));
        Filament::bootCurrentPanel();
        Filament::setTenant($panel === 'portal' ? $this->membership->organization : null);
    }

    public function test_portal_lists_library_templates_and_submits_without_any_game_server(): void
    {
        $this->panel($this->membership->user, 'portal');
        Livewire::test(WorldRequestsPage::class)->callAction('create', [
            'name' => 'Jugendtreff', 'luanti_world_template_id' => $this->template->id,
            'start_date' => now('Europe/Berlin')->toDateString(), 'end_date' => now('Europe/Berlin')->addDay()->toDateString(), 'participant_count' => 12,
        ])->assertHasNoActionErrors()->assertNotified('Weltantrag eingereicht');
        $this->assertDatabaseCount('luanti_servers', 0);
        $this->assertSame($this->template->bundle_sha256, WorldRequest::query()->sole()->template_snapshot['bundle_sha256']);
        Http::assertNothingSent();
        Queue::assertNotPushed(ProvisionWorldRequest::class);
    }

    public function test_commissioned_copies_do_not_duplicate_library_options(): void
    {
        $server = LuantiServer::factory()->create();
        app(PortalWorldTemplates::class)->registerCommissioned($server, app(PortalWorldTemplates::class)->snapshot());
        $options = app(WorldRequests::class)->worldOptions($this->membership->user, $this->membership->organization);
        $this->assertSame([$this->template->id => 'Dev'], $options);
    }

    public function test_admin_approval_dialog_explains_costs_and_queues_provisioning_without_creating_a_vm_inline(): void
    {
        $request = $this->submit();
        $this->panel($this->admin);
        Livewire::test(WorldRequestsPage::class)->mountAction(TestAction::make('approve')->table($request))
            ->assertMountedActionModalSee('kostenpflichtige Hetzner-VM')
            ->fillForm(['name' => 'happy-grove', 'server_type' => 'cx23', 'location' => 'nbg1'])->callMountedAction()
            ->assertHasNoActionErrors()->assertNotified('Weltantrag genehmigt');
        $this->assertSame(WorldRequestStatus::Approved, $request->fresh()->status);
        $this->assertSame('happy-grove', $request->fresh()->provisioning_configuration['name']);
        $this->assertNull($request->fresh()->approved_access);
        Queue::assertPushed(ProvisionWorldRequest::class, fn (ProvisionWorldRequest $job): bool => $job->requestId === $request->id);
        Http::assertNothingSent();
        $this->assertDatabaseCount('luanti_servers', 0);
    }

    public function test_rejection_without_a_server_works_without_any_paid_request(): void
    {
        $request = $this->submit();
        app(WorldRequests::class)->decide(User::factory()->caseworker()->create(), $request, false, 1);
        $this->assertSame(WorldRequestStatus::Rejected, $request->fresh()->status);
        Queue::assertNotPushed(ProvisionWorldRequest::class);
        Http::assertNothingSent();
    }

    public function test_caseworkers_cannot_authorize_paid_server_creation(): void
    {
        $this->expectException(AuthorizationException::class);
        $request = $this->submit();
        app(WorldRequests::class)->decide(User::factory()->caseworker()->create(), $request, true, 1);
    }

    public function test_provisioning_creates_one_owned_vm_and_finalizes_only_after_bridge_and_template_confirmation(): void
    {
        $request = $this->submit();
        $this->approve($request);
        $expected = $request->fresh()->template_snapshot;
        $archive = Storage::disk('local')->path('fixture.zip');
        $zip = new ZipArchive;
        $zip->open($archive);
        $manifest = json_decode($zip->getFromName('manifest.json'), true);
        $content = ['files' => $manifest['files'], 'name' => 'Dev revised', 'template_id' => 'dev'];
        $zip->addFromString('manifest.json', json_encode(['version' => 1, ...$content,
            'revision' => hash('sha256', json_encode($content, JSON_UNESCAPED_SLASHES))]));
        $zip->close();
        app(PortalWorldTemplates::class)->import($this->admin, $archive);
        $this->assertNotSame($expected['revision'], $this->template->fresh()->revision);
        $this->mock(BridgeProvisioning::class)->shouldReceive('prepare')->once()->withArgs(function (User $actor, array $templates) use ($expected): bool {
            $this->assertSame($expected['revision'], $templates[0]['revision']);

            return $actor->id === $this->admin->id;
        })->andReturn(['commit' => str_repeat('a', 40), 'configuration' => ['templates' => [[...$expected, 'is_selectable' => true]]]]);
        Http::fake([
            'https://api.hetzner.cloud/v1/ssh_keys?*' => Http::response(['ssh_keys' => []]),
            'https://api.hetzner.cloud/v1/ssh_keys' => fn (Request $http) => Http::response(['ssh_key' => ['id' => 12, 'name' => $http['name'], 'public_key' => $http['public_key']]], 201),
            'https://api.hetzner.cloud/v1/servers' => fn (Request $http) => Http::response(['server' => ['id' => 42, 'status' => 'running', 'labels' => $http['labels']]], 201),
        ]);
        $service = app(WorldRequestProvisioning::class);
        $service->process($request->id);
        $server = $request->fresh()->provisionedServer;
        $this->assertNotNull($server);
        $this->assertNull($request->fresh()->approved_access);
        Queue::assertPushed(ProvisionLuantiBridge::class);
        $service->process($request->id);
        Http::assertSentCount(3);
        app(PortalWorldTemplates::class)->registerCommissioned($server, [[...$expected, 'is_selectable' => true]]);
        $server->forceFill(['bridge_status' => 'installed', 'luanti_installation_status' => 'verified'])->save();
        $service->process($request->id);
        $service->process($request->id);
        $fresh = $request->fresh();
        $this->assertSame($server->id, $fresh->approved_access['server_id']);
        $this->assertSame($server->templates()->sole()->id, $fresh->luanti_world_template_id);
        $this->assertSame($fresh->current_game_instance_id, $fresh->approved_access['instance_id']);
        $this->assertSame(1, $fresh->gameInstances()->count());
        $this->assertNull($fresh->provisioning_error);
        $this->assertDatabaseCount('luanti_servers', 1);
        Http::assertSentCount(3);
    }

    public function test_unknown_create_result_never_submits_a_second_vm_and_recovers_by_lookup(): void
    {
        $request = $this->submit();
        $this->approve($request);
        $this->mock(BridgeProvisioning::class)->shouldReceive('prepare')->once()
            ->andReturn(['commit' => str_repeat('a', 40), 'configuration' => ['templates' => []]]);
        $lookups = 0;
        Http::fake([
            'https://api.hetzner.cloud/v1/ssh_keys?*' => Http::response(['ssh_keys' => []]),
            'https://api.hetzner.cloud/v1/ssh_keys' => fn (Request $http) => Http::response(['ssh_key' => ['id' => 12, 'name' => $http['name'], 'public_key' => $http['public_key']]], 201),
            'https://api.hetzner.cloud/v1/servers' => Http::failedConnection(),
            'https://api.hetzner.cloud/v1/servers?*' => function () use (&$lookups) {
                $server = LuantiServer::query()->sole();

                return Http::response(['servers' => ++$lookups === 1 ? [] : [[
                    'id' => 42, 'status' => 'running', 'labels' => ['managed-by' => 'luanti-portal', 'portal-reference' => $server->hetzner_reference],
                ]]]);
            },
        ]);
        app(WorldRequestProvisioning::class)->process($request->id);
        app(WorldRequestProvisioning::class)->process($request->id);
        app(WorldRequestProvisioning::class)->process($request->id);
        $server = $request->fresh()->provisionedServer;
        $this->assertSame('running', $server->fresh()->hetzner_status);
        $this->assertDatabaseCount('luanti_servers', 1);
        $this->assertCount(1, Http::recorded(fn (Request $http): bool => $http->method() === 'POST' && str_ends_with($http->url(), '/servers')));
        Http::assertSentCount(5);
    }

    public function test_deleted_server_tombstone_prevents_replacement_after_final_cleanup(): void
    {
        $request = $this->submit();
        $this->approve($request);
        $server = LuantiServer::factory()->hetznerVm()->create(['world_request_id' => $request->id, 'hetzner_status' => 'deleted']);
        app(LuantiServerRegistry::class)->delete($this->admin, $server);
        app(WorldRequestProvisioning::class)->process($request->id);
        $this->assertSoftDeleted($server);
        $this->assertNotNull($request->fresh()->provisioning_error);
        Http::assertNothingSent();
    }

    public function test_pending_vm_cannot_create_accounts_or_publish_a_partial_access_snapshot(): void
    {
        $request = $this->submit();
        $this->approve($request);
        app(LuantiAccounts::class)->reconcile($request->id);
        $this->assertNull($request->fresh()->approved_access);
        $this->actingAs($this->membership->user)->get('/portal/einrichtung/'.$this->membership->organization_id.'/weltantraege/'.$request->id.'/spielaccounts')
            ->assertOk()->assertSee('Spielserver wird bereitgestellt')->assertDontSee('Anlegen und übertragen');
        $this->expectException(ValidationException::class);
        app(LuantiAccounts::class)->create($this->membership->user, $request, [['username' => 'Player', 'password' => 'long-start-password', 'role' => 'participant']]);
    }

    public function test_revoked_admin_access_stops_background_provisioning_before_any_provider_request(): void
    {
        $request = $this->submit();
        $this->approve($request);
        $this->admin->update(['is_active' => false]);
        app(WorldRequestProvisioning::class)->process($request->id);
        $this->assertNotNull($request->fresh()->provisioning_error);
        $this->assertDatabaseCount('luanti_servers', 0);
        Http::assertNothingSent();
    }

    public function test_retry_uses_the_existing_key_setup_and_queues_the_same_request(): void
    {
        $request = $this->submit();
        $this->approve($request);
        $request->forceFill(['provisioning_error' => 'API-Zugang prüfen'])->save();
        $server = LuantiServer::factory()->hetznerVm()->create(['world_request_id' => $request->id, 'hetzner_status' => 'key_pending']);
        $this->mock(HetznerVirtualMachines::class)->shouldReceive('retryKeySetup')->once()
            ->withArgs(fn (User $actor, LuantiServer $existing): bool => $actor->id === $this->admin->id && $existing->id === $server->id)->andReturn($server);

        $this->panel($this->admin);
        Livewire::test(WorldRequestsPage::class)->callAction(TestAction::make('retryProvisioning')->table($request))
            ->assertHasNoActionErrors()->assertNotified('Serverbereitstellung wird erneut geprüft');

        $this->assertNull($request->fresh()->provisioning_error);
        $this->assertSame($server->id, $request->fresh()->provisionedServer->id);
        $this->assertDatabaseCount('luanti_servers', 1);
        Queue::assertPushed(ProvisionWorldRequest::class, 2);
        Http::assertNothingSent();
    }

    public function test_missing_provider_configuration_keeps_the_request_pending(): void
    {
        $request = $this->submit();
        config(['services.hetzner.token' => null]);
        try {
            $this->approve($request);
            $this->fail('Approval succeeded without provider configuration.');
        } catch (ValidationException) {
            $this->assertSame(WorldRequestStatus::Pending, $request->fresh()->status);
        }
        Queue::assertNotPushed(ProvisionWorldRequest::class);
        Http::assertNothingSent();
    }

    public function test_concurrent_retry_reports_the_existing_operation_without_sending_provider_requests(): void
    {
        $request = $this->submit();
        $this->approve($request);
        $lock = Cache::lock('luanti:request-provisioning:'.$request->id, 1260);
        $lock->get();
        try {
            app(WorldRequestProvisioning::class)->retry($this->admin, $request);
            $this->fail('A concurrent provisioning operation was started.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('provisioning', $exception->errors());
        } finally {
            $lock->release();
        }
        Http::assertNothingSent();
        Queue::assertPushed(ProvisionWorldRequest::class, 1);
    }

    public function test_stale_library_approval_does_not_queue_a_vm_after_an_applicant_edit(): void
    {
        $request = $this->submit();
        app(WorldRequests::class)->update($this->membership->user, $request, [
            ...$request->only(['name', 'luanti_world_template_id', 'participant_count']),
            'start_date' => $request->start_date->toDateString(), 'end_date' => $request->end_date->toDateString(),
            'participant_count' => 20,
        ], 1);
        try {
            $this->approve($request);
            $this->fail('A stale approval queued a VM.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('revision', $exception->errors());
        }
        Queue::assertNotPushed(ProvisionWorldRequest::class);
        Http::assertNothingSent();
    }
}
