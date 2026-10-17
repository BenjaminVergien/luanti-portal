<?php

namespace Tests\Feature;

use App\Jobs\ProvisionLuantiBridge;
use App\Models\LuantiGameInstance;
use App\Models\LuantiServer;
use App\Models\LuantiWorldTemplate;
use App\Models\User;
use App\Services\BridgeProvisioning;
use App\Services\HetznerVirtualMachines;
use App\Services\LuantiServerRegistry;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class HetznerVirtualMachinesTest extends TestCase
{
    use RefreshDatabase;

    public function test_vm_creation_queues_bridge_installation_with_the_preflighted_commit(): void
    {
        $admin = User::factory()->globalAdmin()->create();
        Queue::fake();
        $this->mock(BridgeProvisioning::class)->shouldReceive('prepare')->once()->withArgs(fn (User $actor): bool => $actor->id === $admin->id)
            ->andReturn(['commit' => str_repeat('a', 40), 'configuration' => ['repository' => 'Owner/Bridge', 'ref' => 'main']]);
        Http::preventStrayRequests();
        $this->fakeKeyRegistration();
        Http::fake(['https://api.hetzner.cloud/v1/servers' => function () {
            return Http::response(['server' => $this->remoteServer(LuantiServer::query()->sole(), 'initializing')], 201);
        }]);
        $server = app(HetznerVirtualMachines::class)->create($admin, $this->vmData());
        $this->assertSame('pending', $server->bridge_status);
        $this->assertSame(str_repeat('a', 40), $server->bridge_commit);
        $this->assertSame($admin->id, $server->bridge_requested_by);
        Queue::assertPushed(ProvisionLuantiBridge::class, fn ($job): bool => $job->serverId === $server->id);
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.hetzner.token' => 'project-secret']);
    }

    /** @return array{name: string, server_type: string, location: string, image: string} */
    private function vmData(): array
    {
        return ['name' => 'voxelgarden-test', 'server_type' => 'cx23', 'location' => 'nbg1', 'image' => 'ubuntu-24.04'];
    }

    private function fakeKeyRegistration(): void
    {
        Http::fake([
            'https://api.hetzner.cloud/v1/ssh_keys?*' => Http::response(['ssh_keys' => []]),
            'https://api.hetzner.cloud/v1/ssh_keys' => fn (Request $request) => Http::response(['ssh_key' => [
                'id' => 12, 'name' => $request['name'], 'public_key' => $request['public_key'],
            ]], 201),
        ]);
    }

    /** @return array{id: int, name: string, status: string, labels: array<string, string>, public_net: array<string, mixed>} */
    private function remoteServer(LuantiServer $server, string $status = 'running'): array
    {
        return [
            'id' => 42, 'name' => 'voxelgarden-test', 'status' => $status,
            'labels' => ['managed-by' => 'luanti-portal', 'portal-reference' => $server->hetzner_reference],
            'public_net' => ['ipv4' => ['ip' => '192.0.2.42'], 'ipv6' => ['ip' => '2001:db8:1234::/64']],
        ];
    }

    public function test_admin_creates_booting_vm_with_ssh_keys_and_keeps_recovery_reference_before_api_call(): void
    {
        $admin = User::factory()->globalAdmin()->create();
        Http::preventStrayRequests();
        $this->fakeKeyRegistration();
        Http::fake(['https://api.hetzner.cloud/v1/servers' => function (Request $request) {
            $server = LuantiServer::query()->sole();
            $this->assertSame('creating', $server->hetzner_status);
            $this->assertSame($server->hetzner_reference, $request['labels']['portal-reference']);

            return Http::response(['server' => $this->remoteServer($server, 'initializing'), 'root_password' => 'do-not-store'], 201);
        }]);

        $server = app(HetznerVirtualMachines::class)->create($admin, [...$this->vmData(), 'hetzner_server_id' => 999, 'api_token' => 'injected', 'ssh_key_ids' => '999']);

        $this->assertDatabaseHas('luanti_servers', [
            'id' => $server->id, 'domain' => null, 'hetzner_server_id' => 42, 'hetzner_status' => 'initializing',
            'hetzner_ipv4' => '192.0.2.42', 'hetzner_ipv6' => '2001:db8:1234::/64', 'api_token' => null,
        ]);
        $this->assertStringNotContainsString('do-not-store', $server->fresh()->toJson());
        $this->assertStringNotContainsString('project-secret', $server->fresh()->toJson());
        Http::assertSentCount(3);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->hasHeader('Authorization', 'Bearer project-secret')
            && $request['name'] === 'voxelgarden-test' && $request['server_type'] === 'cx23'
            && $request['image'] === 'ubuntu-24.04' && $request['location'] === 'nbg1'
            && $request['ssh_keys'] === [12] && $request['start_after_create'] === true
            && $request['public_net'] === ['enable_ipv4' => true, 'enable_ipv6' => true]
            && ! isset($request['user_data']));
    }

    public function test_missing_api_token_does_not_create_a_record_or_send_a_request(): void
    {
        $admin = User::factory()->globalAdmin()->create();
        config(['services.hetzner.token' => '']);
        Http::preventStrayRequests();

        try {
            app(HetznerVirtualMachines::class)->create($admin, $this->vmData());
            $this->fail('Missing credentials were accepted.');
        } catch (ValidationException $exception) {
            $this->assertSame('Der Hetzner-API-Zugang ist noch nicht konfiguriert.', $exception->errors()['hetzner'][0]);
        }

        Http::assertNothingSent();
        $this->assertDatabaseCount('luanti_servers', 0);
    }

    public function test_hostname_accepts_253_characters_with_labels_up_to_63_characters(): void
    {
        $admin = User::factory()->globalAdmin()->create();
        $name = str_repeat('a', 63).'.'.str_repeat('b', 63).'.'.str_repeat('c', 63).'.'.str_repeat('d', 61);
        Http::preventStrayRequests();
        $this->fakeKeyRegistration();
        Http::fake(['https://api.hetzner.cloud/v1/servers' => fn (Request $request) => Http::response(['server' => [
            'id' => 42, 'status' => 'initializing', 'labels' => $request['labels'],
        ]], 201)]);

        $server = app(HetznerVirtualMachines::class)->create($admin, [...$this->vmData(), 'name' => $name]);

        $this->assertDatabaseHas('luanti_servers', ['id' => $server->id, 'name' => $name, 'hetzner_name' => $name]);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.hetzner.cloud/v1/servers' && $request['name'] === $name);
        Http::assertSentCount(3);
    }

    /** @return array<string, array{string, mixed}> */
    public static function invalidVmData(): array
    {
        return [
            'missing name' => ['name', ''], 'hostname injection' => ['name', '<script>alert(1)</script>'],
            'hostname starts with dash' => ['name', '-test'], 'hostname ends with dash' => ['name', 'test-'],
            'hostname too short' => ['name', 'a'], 'hostname too long' => ['name', str_repeat('a', 64)],
            'hostname total length too long' => ['name', str_repeat('a', 63).'.'.str_repeat('b', 63).'.'.str_repeat('c', 63).'.'.str_repeat('d', 62)],
            'hostname empty label' => ['name', 'test..2'], 'hostname trailing dot' => ['name', 'test.'],
            'hostname label starts with dash' => ['name', 'test.-two'], 'hostname label ends with dash' => ['name', 'test.two-'],
            'missing type' => ['server_type', ''], 'invalid type' => ['server_type', 'https://example.test'],
            'missing location' => ['location', ''], 'invalid location' => ['location', '../servers'],
            'missing image' => ['image', ''], 'invalid image' => ['image', 'image;command'],
        ];
    }

    #[DataProvider('invalidVmData')]
    public function test_invalid_vm_settings_do_not_allocate_a_vm(string $field, mixed $value): void
    {
        $admin = User::factory()->globalAdmin()->create();
        Http::preventStrayRequests();

        try {
            app(HetznerVirtualMachines::class)->create($admin, [...$this->vmData(), $field => $value]);
            $this->fail('Invalid VM settings were accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
        }

        Http::assertNothingSent();
        $this->assertDatabaseCount('luanti_servers', 0);
    }

    public function test_creation_timeout_is_not_retried_and_status_lookup_recovers_the_same_vm(): void
    {
        $admin = User::factory()->globalAdmin()->create();
        Http::preventStrayRequests();
        $this->fakeKeyRegistration();
        Http::fake(['https://api.hetzner.cloud/v1/servers' => Http::failedConnection('private-network-details')]);
        $service = app(HetznerVirtualMachines::class);

        $server = $service->create($admin, $this->vmData());

        $this->assertDatabaseHas('luanti_servers', ['id' => $server->id, 'hetzner_status' => 'unknown', 'hetzner_server_id' => null]);
        Http::assertSentCount(3);
        $this->assertStringNotContainsString('private-network-details', $server->toJson());
        Http::fake(['https://api.hetzner.cloud/v1/servers?*' => Http::response(['servers' => [$this->remoteServer($server)]])]);

        $service->refresh($admin, $server);

        $this->assertDatabaseHas('luanti_servers', ['id' => $server->id, 'hetzner_status' => 'running', 'hetzner_server_id' => 42]);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET' && $request['name'] === 'voxelgarden-test');
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST');
        $this->assertDatabaseCount('luanti_servers', 1);
    }

    public function test_uncertain_creation_prevents_duplicate_allocation_and_local_record_removal(): void
    {
        $admin = User::factory()->globalAdmin()->create();
        $server = LuantiServer::factory()->hetznerVm()->create(['hetzner_server_id' => null, 'hetzner_status' => 'unknown']);
        Http::preventStrayRequests();

        foreach ([
            fn () => app(HetznerVirtualMachines::class)->create($admin, $this->vmData()),
            fn () => app(LuantiServerRegistry::class)->delete($admin, $server),
        ] as $operation) {
            try {
                $operation();
                $this->fail('An unresolved VM record was bypassed.');
            } catch (ValidationException $exception) {
                $this->assertNotEmpty($exception->errors());
            }
        }

        Http::assertNothingSent();
        $this->assertModelExists($server);
        $this->assertDatabaseCount('luanti_servers', 1);
    }

    public function test_missing_vm_after_creation_timeout_stays_unresolved_instead_of_claiming_deletion(): void
    {
        $admin = User::factory()->globalAdmin()->create();
        $server = LuantiServer::factory()->hetznerVm()->create(['hetzner_server_id' => null, 'hetzner_status' => 'unknown']);
        Http::preventStrayRequests();
        Http::fake(['https://api.hetzner.cloud/v1/servers?*' => Http::response(['servers' => []])]);

        app(HetznerVirtualMachines::class)->refresh($admin, $server);

        $this->assertDatabaseHas('luanti_servers', ['id' => $server->id, 'hetzner_status' => 'unknown', 'hetzner_server_id' => null]);
        Http::assertSentCount(1);
    }

    /** @return array<string, array{int, string}> */
    public static function createErrors(): array
    {
        return ['unauthorized' => [401, 'create_failed'], 'unavailable capacity' => [412, 'create_failed'],
            'rate limited' => [429, 'create_failed'], 'upstream error' => [500, 'unknown'], 'redirect' => [302, 'unknown'], 'timeout' => [408, 'unknown']];
    }

    #[DataProvider('createErrors')]
    public function test_api_creation_errors_are_sanitized_and_not_retried(int $status, string $state): void
    {
        $admin = User::factory()->globalAdmin()->create();
        Http::preventStrayRequests();
        $this->fakeKeyRegistration();
        Http::fake(['https://api.hetzner.cloud/v1/servers' => Http::response(['error' => ['message' => 'project-secret private-details']], $status)]);

        $server = app(HetznerVirtualMachines::class)->create($admin, $this->vmData());

        $this->assertDatabaseHas('luanti_servers', ['id' => $server->id, 'hetzner_status' => $state, 'hetzner_server_id' => null]);
        $this->assertStringNotContainsString('private-details', $server->toJson());
        $this->assertStringNotContainsString('project-secret', $server->toJson());
        Http::assertSentCount(3);
    }

    public function test_malformed_create_response_retains_a_recoverable_record(): void
    {
        $admin = User::factory()->globalAdmin()->create();
        Http::preventStrayRequests();
        $this->fakeKeyRegistration();
        Http::fake(['https://api.hetzner.cloud/v1/servers' => Http::response(['server' => ['id' => 42]], 201)]);

        $server = app(HetznerVirtualMachines::class)->create($admin, $this->vmData());

        $this->assertDatabaseHas('luanti_servers', ['id' => $server->id, 'hetzner_status' => 'unknown', 'hetzner_server_id' => null]);
        Http::assertSentCount(3);
    }

    public function test_status_refresh_updates_running_vm_and_handles_absent_public_ips(): void
    {
        $admin = User::factory()->globalAdmin()->create();
        $server = LuantiServer::factory()->hetznerVm()->create(['hetzner_status' => 'initializing', 'hetzner_error' => 'Old error']);
        Http::preventStrayRequests();
        Http::fake(['https://api.hetzner.cloud/v1/servers/42' => Http::response(['server' => [
            ...$this->remoteServer($server), 'public_net' => ['ipv4' => null, 'ipv6' => null],
        ]])]);

        app(HetznerVirtualMachines::class)->refresh($admin, $server);

        $this->assertDatabaseHas('luanti_servers', ['id' => $server->id, 'hetzner_status' => 'running', 'hetzner_error' => null, 'hetzner_ipv4' => null, 'hetzner_ipv6' => null]);
        Http::assertSentCount(1);
    }

    public function test_deletion_checks_ownership_and_only_marks_deleted_after_hetzner_returns_404(): void
    {
        $admin = User::factory()->globalAdmin()->create();
        $server = LuantiServer::factory()->hetznerVm()->create(['api_token' => 'bridge-secret']);
        Http::preventStrayRequests();
        Http::fake([
            'https://api.hetzner.cloud/v1/servers/42' => Http::sequence()
                ->push(['server' => $this->remoteServer($server)])->push(['action' => ['status' => 'running']])
                ->push(['error' => ['code' => 'not_found']], 404),
        ]);
        $service = app(HetznerVirtualMachines::class);

        $service->delete($admin, $server);

        $this->assertDatabaseHas('luanti_servers', ['id' => $server->id, 'hetzner_status' => 'deleting']);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE' && $request->url() === 'https://api.hetzner.cloud/v1/servers/42');

        $service->refresh($admin, $server);
        $service->delete($admin, $server);

        $this->assertDatabaseHas('luanti_servers', ['id' => $server->id, 'hetzner_status' => 'deleted', 'api_token' => null]);
        Http::assertSentCount(3);
        app(LuantiServerRegistry::class)->delete($admin, $server);
        $this->assertModelMissing($server);
    }

    /** @return array<string, array{string}> */
    public static function ownershipMismatches(): array
    {
        return ['missing labels' => ['labels'], 'wrong reference' => ['reference'], 'wrong server id' => ['id'], 'malformed response' => ['malformed']];
    }

    #[DataProvider('ownershipMismatches')]
    public function test_deletion_refuses_unrelated_or_unverifiable_vms(string $mismatch): void
    {
        $admin = User::factory()->globalAdmin()->create();
        $server = LuantiServer::factory()->hetznerVm()->create();
        $remote = $this->remoteServer($server);
        $remote = match ($mismatch) {
            'labels' => [...$remote, 'labels' => []],
            'reference' => [...$remote, 'labels' => ['managed-by' => 'luanti-portal', 'portal-reference' => 'another-record']],
            'id' => [...$remote, 'id' => 99], default => null,
        };
        Http::preventStrayRequests();
        Http::fake(['https://api.hetzner.cloud/v1/servers/42' => Http::response(['server' => $remote])]);

        try {
            app(HetznerVirtualMachines::class)->delete($admin, $server);
            $this->fail('Unrelated VM could be deleted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('hetzner', $exception->errors());
        }

        Http::assertSentCount(1);
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'DELETE');
        $this->assertDatabaseHas('luanti_servers', ['id' => $server->id, 'hetzner_status' => 'running']);
    }

    public function test_vm_deletion_is_blocked_when_world_templates_or_game_instances_exist(): void
    {
        $admin = User::factory()->globalAdmin()->create();
        $server = LuantiServer::factory()->hetznerVm()->create();
        $template = LuantiWorldTemplate::factory()->for($server, 'server')->create();
        Http::preventStrayRequests();

        try {
            app(HetznerVirtualMachines::class)->delete($admin, $server);
            $this->fail('VM containing templates could be deleted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('hetzner', $exception->errors());
        }

        $template->delete();
        LuantiGameInstance::factory()->create(['luanti_server_id' => $server->id]);
        try {
            app(HetznerVirtualMachines::class)->delete($admin, $server);
            $this->fail('VM containing game instances could be deleted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('hetzner', $exception->errors());
        }

        Http::assertNothingSent();
        $this->assertModelExists($server);
    }

    public function test_protected_vm_deletion_error_keeps_vm_record_and_sanitizes_response(): void
    {
        $admin = User::factory()->globalAdmin()->create();
        $server = LuantiServer::factory()->hetznerVm()->create();
        Http::preventStrayRequests();
        Http::fake(['https://api.hetzner.cloud/v1/servers/42' => Http::sequence()
            ->push(['server' => $this->remoteServer($server)])
            ->push(['error' => ['message' => 'private-details']], 423)]);

        app(HetznerVirtualMachines::class)->delete($admin, $server);

        $this->assertDatabaseHas('luanti_servers', ['id' => $server->id, 'hetzner_status' => 'running']);
        $this->assertStringNotContainsString('private-details', $server->fresh()->toJson());
        $this->assertNotNull($server->fresh()->hetzner_error);
        Http::assertSentCount(2);
    }

    public function test_deletion_timeout_keeps_record_for_status_refresh(): void
    {
        $admin = User::factory()->globalAdmin()->create();
        $server = LuantiServer::factory()->hetznerVm()->create();
        Http::preventStrayRequests();
        Http::fake(['https://api.hetzner.cloud/v1/servers/42' => fn (Request $request) => $request->method() === 'DELETE'
            ? Http::failedConnection() : Http::response(['server' => $this->remoteServer($server)])]);

        app(HetznerVirtualMachines::class)->delete($admin, $server);

        $this->assertModelExists($server);
        $this->assertNotNull($server->fresh()->hetzner_error);
        Http::assertSentCount(2);
    }

    public function test_concurrent_vm_operation_is_refused_before_http_calls(): void
    {
        $admin = User::factory()->globalAdmin()->create();
        $server = LuantiServer::factory()->hetznerVm()->create();
        $lock = Cache::lock('hetzner:vm:'.$server->id, 120);
        $lock->get();
        Http::preventStrayRequests();

        try {
            app(HetznerVirtualMachines::class)->delete($admin, $server);
            $this->fail('Concurrent VM operation was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('hetzner', $exception->errors());
        } finally {
            $lock->release();
        }

        Http::assertNothingSent();
        $this->assertModelExists($server);
    }

    /** @return array<string, array{string}> */
    public static function deniedActors(): array
    {
        return ['caseworker' => ['caseworker'], 'ordinary user' => ['ordinary'], 'inactive admin' => ['inactive'],
            'unverified admin' => ['unverified'], 'demoted admin' => ['demoted']];
    }

    #[DataProvider('deniedActors')]
    public function test_non_admins_cannot_create_refresh_or_delete_vms(string $role): void
    {
        $actor = match ($role) {
            'caseworker' => User::factory()->caseworker()->create(),
            'inactive' => User::factory()->globalAdmin()->inactive()->create(),
            'unverified' => User::factory()->globalAdmin()->unverified()->create(),
            'demoted' => User::factory()->globalAdmin()->create(), default => User::factory()->create(),
        };
        if ($role === 'demoted') {
            $actor->fresh()->forceFill(['internal_role' => null])->save();
        }
        $server = LuantiServer::factory()->hetznerVm()->create();
        $service = app(HetznerVirtualMachines::class);
        Http::preventStrayRequests();

        foreach ([fn () => $service->create($actor, $this->vmData()), fn () => $service->refresh($actor, $server), fn () => $service->delete($actor, $server)] as $operation) {
            try {
                $operation();
                $this->fail('Unauthorized cloud operation was accepted.');
            } catch (AuthorizationException|HttpException $exception) {
                if ($exception instanceof HttpException) {
                    $this->assertSame(403, $exception->getStatusCode());
                }
            }
        }

        Http::assertNothingSent();
        $this->assertDatabaseCount('luanti_servers', 1);
        $this->assertDatabaseHas('luanti_servers', ['id' => $server->id, 'hetzner_status' => 'running']);
    }
}
