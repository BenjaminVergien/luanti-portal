<?php

namespace Tests\Feature;

use App\Filament\Pages\LuantiServers;
use App\Filament\Pages\Settings;
use App\Jobs\CleanupLuantiDns;
use App\Jobs\ProvisionLuantiBridge;
use App\Models\LuantiServer;
use App\Models\User;
use App\Services\BridgeHttpClient;
use App\Services\BridgeProvisioning;
use App\Services\HetznerDns;
use App\Services\HetznerVirtualMachines;
use App\Services\LuantiImageCatalog;
use App\Services\LuantiServerRegistry;
use App\Services\LuantiSshClient;
use App\Services\PortalSettings;
use App\Services\PublicEndpointPendingException;
use App\Services\PublicHttpsProvisioning;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Bus\UniqueLock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class PublicHttpsProvisioningTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        Cache::forever(LuantiImageCatalog::CacheKey, ['images' => ['ghcr.io/luanti-org/luanti:5.17.0' => '5.17.0'], 'fetched_at' => now()->timestamp]);
        config(['services.hetzner.token' => 'vm-project-secret']);
        Http::preventStrayRequests();
        Queue::fake();

        return User::factory()->globalAdmin()->create();
    }

    private function configure(User $actor): void
    {
        app(PortalSettings::class)->save($actor, ['public_https_enabled' => true, 'bridge_enabled' => true,
            'luanti_docker_image' => 'ghcr.io/luanti-org/luanti:5.17.0', 'dns_api_token' => 'dns-project-secret', 'acme_email' => 'admin@example.test']);
    }

    private function server(User $actor): LuantiServer
    {
        $this->configure($actor);

        return LuantiServer::factory()->hetznerVm()->create(['hetzner_ipv4' => '192.0.2.42',
            'public_hostname' => 'amber-otter.bensfoundry.de',
            'public_https_configuration' => ['zone_id' => 99, 'zone_name' => 'bensfoundry.de', 'label' => 'amber-otter', 'acme_email' => 'admin@example.test'],
            'dns_status' => 'pending', 'https_status' => 'pending', 'dns_ipv4' => '192.0.2.42',
            'bridge_status' => 'pending', 'bridge_requested_by' => $actor->id,
            'bridge_configuration' => ['admin_name' => 'Administrator', 'source_sha256' => str_repeat('b', 64)],
            'bridge_commit' => str_repeat('a', 40), 'luanti_installation_status' => 'verified']);
    }

    private function fakeZone(): void
    {
        Http::fake(['https://api.hetzner.cloud/v1/zones/99' => Http::response(['zone' => ['id' => 99, 'name' => 'bensfoundry.de', 'mode' => 'primary']]),
            'https://api.hetzner.cloud/v1/zones/bensfoundry.de' => Http::response(['zone' => ['id' => 99, 'name' => 'bensfoundry.de', 'mode' => 'primary']]),
            'https://api.hetzner.cloud/v1/zones/99/rrsets/*/AAAA' => Http::response([], 404),
            'https://api.hetzner.cloud/v1/zones/99/rrsets/*/CNAME' => Http::response([], 404)]);
    }

    /** @return array{name: string, type: string, records: list<array{value: string, comment: string}>} */
    private function record(LuantiServer $server): array
    {
        return ['name' => 'amber-otter', 'type' => 'A', 'records' => [['value' => '192.0.2.42', 'comment' => 'luanti-portal:'.$server->hetzner_reference]]];
    }

    private function response(int $status, array $data = []): Response
    {
        return new Response(new \GuzzleHttp\Psr7\Response($status, ['Content-Type' => 'application/json'], json_encode($data)));
    }

    public function test_settings_are_disabled_by_default_and_dns_secret_never_returns_to_livewire(): void
    {
        $actor = $this->admin();
        $this->assertFalse(app(PortalSettings::class)->publicHttps()['public_https_enabled']);
        $this->actingAs($actor);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::bootCurrentPanel();
        $page = Livewire::test(Settings::class)->fillForm(['public_https_enabled' => true, 'bridge_enabled' => true,
            'luanti_docker_image' => 'ghcr.io/luanti-org/luanti:5.17.0', 'dns_api_token' => 'dns-project-secret', 'acme_email' => 'admin@example.test'])
            ->call('save')->assertHasNoFormErrors()->assertFormSet(['dns_api_token' => null]);
        $this->assertSame('dns-project-secret', Crypt::decryptString(DB::table('portal_settings')->value('dns_api_token')));
        $this->assertStringNotContainsString('dns-project-secret', json_encode([$page->snapshot, $page->effects]));
        Livewire::test(Settings::class)->call('save')->assertHasNoFormErrors();
        $this->assertSame('dns-project-secret', app(PortalSettings::class)->dnsToken());
        $this->fakeZone();
        $page->callAction('checkDns')->assertNotified('DNS-Zone erreichbar');
        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET' && $request->hasHeader('Authorization', 'Bearer dns-project-secret'));
        $page->callAction('removeDnsToken')->assertNotified('DNS-Token entfernt');
        $this->assertNull(app(PortalSettings::class)->dnsToken());
        $this->assertFalse(app(PortalSettings::class)->publicHttps()['public_https_enabled']);
    }

    public function test_settings_require_bridge_token_and_contact_and_reject_clearing_required_values(): void
    {
        $actor = $this->admin();
        $this->configure($actor);
        foreach ([['bridge_enabled' => false], ['luanti_docker_image' => null], ['acme_email' => null], ['dns_zone' => 'https://invalid.test']] as $data) {
            try {
                app(PortalSettings::class)->save($actor, $data);
                $this->fail('Invalid HTTPS settings were accepted.');
            } catch (ValidationException) {
                $this->assertTrue(app(PortalSettings::class)->publicHttps()['public_https_enabled']);
                $this->assertSame('admin@example.test', app(PortalSettings::class)->publicHttps()['acme_email']);
            }
        }
        app(PortalSettings::class)->removeDnsToken($actor);
        try {
            app(PortalSettings::class)->save($actor, ['public_https_enabled' => true]);
            $this->fail('Enabled without DNS token.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('dns_api_token', $exception->errors());
        }
        $this->expectException(AuthorizationException::class);
        app(PortalSettings::class)->save(User::factory()->create(), ['dns_api_token' => 'forbidden']);
    }

    public function test_preflight_rejects_foreign_dns_and_dotted_names_before_paid_vm_request(): void
    {
        $actor = $this->admin();
        $this->configure($actor);
        $this->mock(BridgeProvisioning::class)->shouldReceive('prepare')->andReturn(['commit' => str_repeat('a', 40), 'configuration' => []]);
        $this->fakeZone();
        Http::fake(['https://api.hetzner.cloud/v1/zones/99/rrsets/amber-otter/A' => Http::response(['rrset' => ['name' => 'amber-otter', 'type' => 'A', 'records' => [['value' => '192.0.2.5']]]])]);
        foreach (['amber-otter', 'dotted.name'] as $name) {
            try {
                app(HetznerVirtualMachines::class)->create($actor, ['name' => $name, 'server_type' => 'cx23', 'image' => 'ubuntu-24.04', 'location' => 'nbg1']);
                $this->fail('Requested paid VM with invalid DNS name.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('name', $exception->errors());
            }
        }
        $this->assertDatabaseCount('luanti_servers', 0);
        Http::assertNotSent(fn (Request $request): bool => $request->method() !== 'GET');
    }

    public function test_name_reservation_and_creation_lock_prevent_duplicate_requests(): void
    {
        $actor = $this->admin();
        $server = $this->server($actor);
        $this->fakeZone();
        try {
            app(HetznerDns::class)->prepare('amber-otter');
            $this->fail('Reserved hostname was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('name', $exception->errors());
        }
        $this->mock(BridgeProvisioning::class)->shouldReceive('prepare')->andReturn(['commit' => str_repeat('a', 40), 'configuration' => []]);
        $lock = Cache::lock('hetzner:vm:create:another-otter', 120);
        $lock->get();
        try {
            app(HetznerVirtualMachines::class)->create($actor, ['name' => 'another-otter', 'server_type' => 'cx23', 'image' => 'ubuntu-24.04', 'location' => 'nbg1']);
            $this->fail('Concurrent creation was accepted.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('luanti_servers', 1);
        } finally {
            $lock->release();
        }
        $this->assertMatchesRegularExpression('/^[a-z]+-[a-z]+$/', HetznerVirtualMachines::generatedName());
        $this->assertArrayNotHasKey('public_https_configuration', $server->toArray());
    }

    public function test_dns_creation_uses_separate_token_and_recovers_an_ambiguous_result_without_duplicate_write(): void
    {
        $actor = $this->admin();
        $server = $this->server($actor);
        $this->fakeZone();
        Http::fake(['https://api.hetzner.cloud/v1/zones/99/rrsets/amber-otter/A' => Http::sequence()->push([], 404)->push(['rrset' => $this->record($server)]),
            'https://api.hetzner.cloud/v1/zones/99/rrsets' => Http::failedConnection('private-dns-detail')]);
        try {
            app(HetznerDns::class)->ensureRecord($server);
            $this->fail('Ambiguous response accepted.');
        } catch (PublicEndpointPendingException $exception) {
            $this->assertStringNotContainsString('private-dns-detail', $exception->getMessage());
        }
        app(HetznerDns::class)->ensureRecord($server->fresh());
        $this->assertSame('ready', $server->fresh()->dns_status);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->hasHeader('Authorization', 'Bearer dns-project-secret') && $request['ttl'] === 300
            && $request['records'][0]['comment'] === 'luanti-portal:'.$server->hetzner_reference);
        $this->assertCount(1, Http::recorded(fn (Request $request): bool => $request->method() === 'POST'));
    }

    public function test_dns_waits_for_action_and_never_accepts_changed_records_or_zone_identity(): void
    {
        $actor = $this->admin();
        $server = $this->server($actor);
        $this->fakeZone();
        $server->forceFill(['dns_action_id' => 7])->save();
        Http::fake(['https://api.hetzner.cloud/v1/zones/99/actions/7' => Http::sequence()->push(['action' => ['status' => 'running']])->push(['action' => ['status' => 'success']]),
            'https://api.hetzner.cloud/v1/zones/99/rrsets/amber-otter/A' => Http::response(['rrset' => array_replace($this->record($server), ['records' => [['value' => '192.0.2.9', 'comment' => 'foreign']]])])]);
        try {
            app(HetznerDns::class)->ensureRecord($server);
            $this->fail('Pending action accepted.');
        } catch (PublicEndpointPendingException) {
            $this->assertSame(7, $server->fresh()->dns_action_id);
        }
        try {
            app(HetznerDns::class)->ensureRecord($server->fresh());
            $this->fail('Changed record accepted.');
        } catch (ValidationException) {
            $this->assertNull($server->fresh()->dns_action_id);
            Http::assertNotSent(fn (Request $request): bool => $request->method() !== 'GET');
        }
        Http::fake(['https://api.hetzner.cloud/v1/zones/99' => Http::response(['zone' => ['id' => 99, 'name' => 'other.test', 'mode' => 'primary']])]);
        $this->expectException(ValidationException::class);
        app(HetznerDns::class)->ensureRecord($server->fresh());
    }

    public function test_new_vm_snapshots_hostname_and_queues_public_provisioning(): void
    {
        $actor = $this->admin();
        $this->configure($actor);
        $this->fakeZone();
        $this->mock(BridgeProvisioning::class)->shouldReceive('prepare')->once()->andReturn(['commit' => str_repeat('a', 40), 'configuration' => ['admin_name' => 'Administrator']]);
        Http::fake(['https://api.hetzner.cloud/v1/zones/99/rrsets/amber-otter/A' => Http::response([], 404),
            'https://api.hetzner.cloud/v1/ssh_keys?*' => Http::response(['ssh_keys' => []]),
            'https://api.hetzner.cloud/v1/ssh_keys' => fn (Request $request) => Http::response(['ssh_key' => ['id' => 12, 'name' => $request['name'], 'public_key' => $request['public_key']]], 201),
            'https://api.hetzner.cloud/v1/servers' => fn (Request $request) => Http::response(['server' => [
                'id' => 42, 'status' => 'initializing', 'labels' => $request['labels'], 'public_net' => ['ipv4' => ['ip' => '192.0.2.42']],
            ]], 201)]);
        $server = app(HetznerVirtualMachines::class)->create($actor, ['name' => 'amber-otter', 'server_type' => 'cx23', 'image' => 'ubuntu-24.04', 'location' => 'nbg1']);
        $this->assertSame('amber-otter.bensfoundry.de', $server->public_hostname);
        $this->assertSame(99, $server->public_https_configuration['zone_id']);
        $this->assertSame('pending', $server->https_status);
        $this->assertNull($server->api_base_url);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.hetzner.cloud/v1/servers'
            && $request->hasHeader('Authorization', 'Bearer vm-project-secret')
            && ! str_contains(json_encode($request->data()), 'dns-project-secret'));
        Queue::assertPushed(ProvisionLuantiBridge::class);
        app(PortalSettings::class)->save($actor, ['dns_zone' => 'changed.example.test']);
        $this->assertSame('bensfoundry.de', $server->fresh()->public_https_configuration['zone_name']);
    }

    public function test_invalid_public_authentication_or_tls_does_not_pass_verification(): void
    {
        $actor = $this->admin();
        $server = $this->server($actor);
        $server->forceFill(['api_token' => str_repeat('t', 43)])->save();
        $this->mock(BridgeHttpClient::class)->shouldReceive('send')->once()->andReturn($this->response(200));
        try {
            app(PublicHttpsProvisioning::class)->verify($server);
            $this->fail('API without authentication was accepted.');
        } catch (PublicEndpointPendingException) {
            $this->assertNull($server->fresh()->api_base_url);
        }
        $this->mock(BridgeHttpClient::class)->shouldReceive('send')->once()->andThrow(new ConnectionException('Certificate validation failed.'));
        $this->expectException(ConnectionException::class);
        app(PublicHttpsProvisioning::class)->verify($server);
    }

    public function test_vm_deletion_only_queues_dns_cleanup_after_remote_absence_is_confirmed(): void
    {
        $actor = $this->admin();
        $server = $this->server($actor);
        $remote = ['id' => 42, 'status' => 'running', 'labels' => ['managed-by' => 'luanti-portal', 'portal-reference' => $server->hetzner_reference],
            'public_net' => ['ipv4' => ['ip' => '192.0.2.42']]];
        Http::fake(['https://api.hetzner.cloud/v1/servers/42' => function (Request $request) use (&$remote) {
            if ($request->method() === 'DELETE') {
                $remote = null;

                return Http::response(['action' => ['status' => 'running']]);
            }

            return $remote === null ? Http::response([], 404) : Http::response(['server' => $remote]);
        }]);
        app(HetznerVirtualMachines::class)->delete($actor, $server);
        $this->assertSame('deleting', $server->fresh()->hetzner_status);
        Queue::assertNotPushed(CleanupLuantiDns::class);
        app(HetznerVirtualMachines::class)->refresh($actor, $server->fresh());
        $this->assertSame('deleted', $server->fresh()->hetzner_status);
        $this->assertTrue($server->fresh()->dns_cleanup_pending);
        $this->assertSame('192.0.2.42', $server->fresh()->dns_ipv4);
        Queue::assertPushed(CleanupLuantiDns::class, fn (CleanupLuantiDns $job): bool => $job->serverId === $server->id);
    }

    public function test_https_retry_skips_completed_bridge_installation_and_only_publishes_after_public_checks(): void
    {
        $actor = $this->admin();
        $server = $this->server($actor);
        $server->forceFill(['bridge_installed_at' => now(), 'api_token' => str_repeat('t', 43)])->save();
        $this->mock(HetznerVirtualMachines::class)->shouldReceive('refresh')->andReturnUsing(fn (): LuantiServer => $server->fresh());
        $this->mock(HetznerDns::class)->shouldReceive('ensureRecord')->twice();
        $this->mock(LuantiSshClient::class)->shouldReceive('execute')->twice()->withArgs(function (LuantiServer $vm, string $script): bool {
            preg_match('/b64decode\("([A-Za-z0-9+\/=]+)"\)/', $script, $matches);
            $payload = json_decode(base64_decode($matches[1]), true);
            $this->assertArrayNotHasKey('source', $payload);
            $this->assertStringNotContainsString('dns-project-secret', $script);
            $this->assertSame('admin@example.test', $payload['email']);

            return true;
        })->andReturn('{"status":"certificate_pending"}', json_encode(['status' => 'ready', 'hostname' => $server->public_hostname, 'reference' => $server->hetzner_reference]));
        $this->mock(BridgeHttpClient::class)->shouldReceive('send')->once()->with('GET', \Mockery::pattern('#^https://amber-otter.bensfoundry.de/v1/lifecycle/jobs/#'), '', 10)->andReturn($this->response(401))
            ->shouldReceive('send')->once()->with('GET', \Mockery::any(), str_repeat('t', 43), 10)->andReturn($this->response(404, ['version' => 1, 'error' => ['code' => 'unknown_job']]));
        $this->assertTrue(app(BridgeProvisioning::class)->process($server->id));
        $this->assertNull($server->fresh()->api_base_url);
        $this->assertNotNull($server->fresh()->public_provisioning_next_at);
        $this->travel(31)->seconds();
        $this->assertFalse(app(BridgeProvisioning::class)->process($server->id));
        $this->assertSame('https://amber-otter.bensfoundry.de', $server->fresh()->api_base_url);
        $this->assertSame('ready', $server->fresh()->https_status);
        $this->assertSame(str_repeat('t', 43), $server->fresh()->api_token);
    }

    public function test_dns_delays_exhaust_backoff_then_explicit_retry_preserves_snapshot(): void
    {
        $actor = $this->admin();
        $server = $this->server($actor);
        $server->forceFill(['public_provisioning_attempts' => 11])->save();
        $this->mock(HetznerVirtualMachines::class)->shouldReceive('refresh')->once()->andReturn($server);
        $this->mock(HetznerDns::class)->shouldReceive('ensureRecord')->once()->andThrow(new PublicEndpointPendingException('DNS wartet.'));
        $this->mock(LuantiSshClient::class)->shouldNotReceive('execute');
        $this->assertFalse(app(BridgeProvisioning::class)->process($server->id));
        $this->assertSame('failed', $server->fresh()->bridge_status);
        $snapshot = $server->public_https_configuration;
        app(PortalSettings::class)->save($actor, ['dns_zone' => 'changed.example.test', 'acme_email' => 'changed@example.test']);
        app(BridgeProvisioning::class)->retry($actor, $server->fresh());
        $this->assertSame($snapshot, $server->fresh()->public_https_configuration);
        $this->assertSame(0, $server->fresh()->public_provisioning_attempts);
        Queue::assertPushed(ProvisionLuantiBridge::class);
    }

    public function test_cleanup_waits_for_confirmed_deletion_and_preserves_metadata_on_failure(): void
    {
        $actor = $this->admin();
        $server = $this->server($actor);
        $server->forceFill(['dns_cleanup_pending' => true])->save();
        app(HetznerDns::class)->cleanup($server);
        Http::assertNothingSent();
        $server->forceFill(['hetzner_status' => 'deleted'])->save();
        $this->fakeZone();
        $reads = 0;
        Http::fake(['https://api.hetzner.cloud/v1/zones/99/rrsets/amber-otter/A' => function (Request $request) use ($server, &$reads) {
            return $request->method() === 'DELETE' ? Http::response([], 204)
                : (++$reads === 1 ? Http::response(['rrset' => $this->record($server)]) : Http::response([], 404));
        }]);
        (new CleanupLuantiDns($server->id))->handle(app(HetznerDns::class));
        $this->assertTrue($server->fresh()->dns_cleanup_pending);
        $this->assertSame('192.0.2.42', $server->fresh()->dns_ipv4);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE');
        $this->travel(31)->seconds();
        (new CleanupLuantiDns($server->id))->handle(app(HetznerDns::class));
        $this->assertFalse($server->fresh()->dns_cleanup_pending);
        $this->assertSame('deleted', $server->fresh()->dns_status);
    }

    public function test_explicit_retry_can_bypass_a_previously_delayed_unique_job(): void
    {
        $actor = $this->admin();
        $server = $this->server($actor);
        $server->forceFill(['public_provisioning_next_at' => now()->addMinutes(15)])->save();
        $this->assertTrue(app(UniqueLock::class)->acquire(new ProvisionLuantiBridge($server->id)));
        app(BridgeProvisioning::class)->retry($actor, $server);
        Queue::assertPushed(ProvisionLuantiBridge::class, fn (ProvisionLuantiBridge $job): bool => $job->serverId === $server->id && $job->delay === null);
        $this->assertNull($server->fresh()->public_provisioning_next_at);
    }

    public function test_cleanup_refuses_foreign_records_and_portal_deletion_cannot_discard_retry_metadata(): void
    {
        $actor = $this->admin();
        $server = $this->server($actor);
        $server->forceFill(['hetzner_status' => 'deleted', 'dns_cleanup_pending' => true])->save();
        $this->fakeZone();
        Http::fake(['https://api.hetzner.cloud/v1/zones/99/rrsets/amber-otter/A' => Http::response(['rrset' => ['name' => 'amber-otter', 'type' => 'A', 'records' => [['value' => '192.0.2.42', 'comment' => 'foreign']]]])]);
        (new CleanupLuantiDns($server->id))->handle(app(HetznerDns::class));
        $this->assertSame(12, $server->fresh()->dns_cleanup_attempts);
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'DELETE');
        $this->expectException(ValidationException::class);
        app(LuantiServerRegistry::class)->delete($actor, $server);
    }

    public function test_vm_name_suggestion_and_regenerate_action_are_available(): void
    {
        $actor = $this->admin();
        $this->actingAs($actor);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::bootCurrentPanel();
        $page = Livewire::test(LuantiServers::class)->mountAction('createVm');
        $name = $page->get('mountedActions.0.data.name');
        $this->assertMatchesRegularExpression('/^[a-z]+-[a-z]+$/', $name);
        $page->assertMountedActionModalSee('Neuen Namen vorschlagen');
        $page->fillForm(['name' => 'manually-set-fixture'])
            ->callAction(TestAction::make('generateName')->schemaComponent('name'));
        $this->assertMatchesRegularExpression('/^[a-z]+-[a-z]+$/', $page->get('mountedActions.0.data.name'));
        $this->assertNotSame('manually-set-fixture', $page->get('mountedActions.0.data.name'));
    }
}
