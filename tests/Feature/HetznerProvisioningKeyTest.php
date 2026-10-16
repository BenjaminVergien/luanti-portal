<?php

namespace Tests\Feature;

use App\Models\LuantiServer;
use App\Models\User;
use App\Services\HetznerProvisioningKey;
use App\Services\HetznerVirtualMachines;
use App\Services\LuantiServerRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class HetznerProvisioningKeyTest extends TestCase
{
    use RefreshDatabase;

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

    private function pendingServer(): LuantiServer
    {
        return LuantiServer::factory()->hetznerVm()->create([
            'hetzner_status' => 'key_pending', 'hetzner_server_id' => null, 'hetzner_create_payload' => $this->vmData(),
        ]);
    }

    private function fakeRegistration(): void
    {
        Http::fake([
            'https://api.hetzner.cloud/v1/ssh_keys?*' => Http::response(['ssh_keys' => []]),
            'https://api.hetzner.cloud/v1/ssh_keys' => fn (Request $request) => Http::response(['ssh_key' => [
                'id' => 12, 'name' => $request['name'], 'public_key' => $request['public_key'],
            ]], 201),
        ]);
    }

    /** @return array{id: int, name: string, public_key: string} */
    private function remoteKey(LuantiServer $server): array
    {
        return ['id' => 12, 'name' => 'luanti-portal-'.$server->hetzner_reference, 'public_key' => $server->provisioning_public_key];
    }

    public function test_generated_private_key_is_encrypted_and_public_key_contains_matching_rsa_parameters(): void
    {
        $server = $this->pendingServer();
        Http::preventStrayRequests();
        $this->fakeRegistration();

        $keyId = app(HetznerProvisioningKey::class)->ensureRegistered($server);

        $this->assertSame(12, $keyId);
        $privateKey = $server->fresh()->provisioning_private_key;
        $rawKey = DB::table('luanti_servers')->where('id', $server->id)->value('provisioning_private_key');
        $this->assertNotSame($privateKey, $rawKey);
        $this->assertStringNotContainsString('PRIVATE KEY', $rawKey);
        $details = openssl_pkey_get_details(openssl_pkey_get_private($privateKey));
        $this->assertSame(3072, $details['bits']);
        $binary = base64_decode(explode(' ', $server->provisioning_public_key)[1], true);
        $parts = [];
        while ($binary !== '') {
            $length = unpack('Nlength', substr($binary, 0, 4))['length'];
            $parts[] = substr($binary, 4, $length);
            $binary = substr($binary, 4 + $length);
        }
        $this->assertCount(3, $parts);
        $this->assertSame('ssh-rsa', $parts[0]);
        $this->assertSame($details['rsa']['e'], ltrim($parts[1], "\0"));
        $this->assertSame($details['rsa']['n'], ltrim($parts[2], "\0"));
        $this->assertSame(0, ord($parts[2][0]));
        $this->assertStringNotContainsString($privateKey, $server->fresh()->toJson());
        $this->assertStringNotContainsString($rawKey, $server->fresh()->toJson());
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request['public_key'] === $server->provisioning_public_key
            && ! str_contains($request->body(), 'PRIVATE KEY') && ! str_contains($request->body(), $rawKey));
    }

    public function test_each_vm_gets_a_different_key_pair_and_registration_name(): void
    {
        $first = $this->pendingServer();
        $second = LuantiServer::factory()->hetznerVm()->create(['hetzner_server_id' => null, 'hetzner_name' => 'second']);
        Http::preventStrayRequests();
        $this->fakeRegistration();
        $service = app(HetznerProvisioningKey::class);

        $service->ensureRegistered($first);
        $service->ensureRegistered($second);

        $this->assertNotSame($first->provisioning_public_key, $second->provisioning_public_key);
        $this->assertNotSame($first->provisioning_private_key, $second->provisioning_private_key);
        Http::assertSentCount(4);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && $request['name'] === 'luanti-portal-'.$first->hetzner_reference);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && $request['name'] === 'luanti-portal-'.$second->hetzner_reference);
    }

    public function test_registration_timeout_keeps_key_and_resume_recovers_registration_before_creating_vm_once(): void
    {
        $admin = User::factory()->globalAdmin()->create();
        Http::preventStrayRequests();
        $registered = false;
        Http::fake([
            'https://api.hetzner.cloud/v1/ssh_keys?*' => function () use (&$registered) {
                return Http::response(['ssh_keys' => $registered ? [$this->remoteKey(LuantiServer::query()->sole())] : []]);
            },
            'https://api.hetzner.cloud/v1/ssh_keys' => function () use (&$registered) {
                $registered = true;

                return Http::failedConnection('private-details');
            },
        ]);
        $service = app(HetznerVirtualMachines::class);

        $server = $service->create($admin, $this->vmData());

        $this->assertSame('key_pending', $server->hetzner_status);
        $privateKey = $server->getRawOriginal('provisioning_private_key');
        $this->assertNotNull($privateKey);
        Http::assertSentCount(2);
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/servers'));
        $this->assertStringNotContainsString('private-details', $server->toJson());
        Http::fake([
            'https://api.hetzner.cloud/v1/servers' => fn (Request $request) => Http::response(['server' => [
                'id' => 42, 'status' => 'initializing', 'labels' => $request['labels'],
            ]], 201),
        ]);

        $service->retryKeySetup($admin, $server);

        $this->assertDatabaseHas('luanti_servers', ['id' => $server->id, 'hetzner_status' => 'initializing', 'provisioning_private_key' => $privateKey]);
        $this->assertDatabaseCount('luanti_servers', 1);
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && $request->url() === 'https://api.hetzner.cloud/v1/servers' && $request['ssh_keys'] === [12]);
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST' && str_contains($request->url(), '/ssh_keys'));
    }

    public function test_unrelated_registered_key_is_neither_used_nor_deleted(): void
    {
        $server = $this->pendingServer();
        Http::preventStrayRequests();
        $this->fakeRegistration();
        $service = app(HetznerProvisioningKey::class);
        $service->ensureRegistered($server);
        Http::fake(['https://api.hetzner.cloud/v1/ssh_keys/12' => Http::response(['ssh_key' => [
            ...$this->remoteKey($server), 'public_key' => 'ssh-rsa unrelated-key',
        ]])]);

        foreach ([fn () => $service->ensureRegistered($server), fn () => $service->removeRegisteredKey($server)] as $operation) {
            try {
                $operation();
                $this->fail('An unrelated key was accepted.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('hetzner', $exception->errors());
            }
        }

        Http::assertSentCount(2);
        Http::assertNotSent(fn (Request $request): bool => $request->method() !== 'GET');
        $this->assertNotNull($server->fresh()->provisioning_private_key);
    }

    public function test_confirmed_vm_deletion_removes_its_key_and_clears_private_material(): void
    {
        $admin = User::factory()->globalAdmin()->create();
        $server = $this->pendingServer();
        Http::preventStrayRequests();
        $this->fakeRegistration();
        app(HetznerProvisioningKey::class)->ensureRegistered($server);
        $server->forceFill(['hetzner_server_id' => 42, 'hetzner_status' => 'running'])->save();
        Http::fake([
            'https://api.hetzner.cloud/v1/servers/42' => Http::sequence()->push(['server' => [
                'id' => 42, 'status' => 'running', 'labels' => ['managed-by' => 'luanti-portal', 'portal-reference' => $server->hetzner_reference],
            ]])->push(['action' => ['status' => 'running']])->push([], 404),
            'https://api.hetzner.cloud/v1/ssh_keys/12' => Http::sequence()->push(['ssh_key' => $this->remoteKey($server)])->pushStatus(204),
        ]);
        $service = app(HetznerVirtualMachines::class);

        $service->delete($admin, $server);

        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/ssh_keys'));
        $this->assertNotNull($server->fresh()->provisioning_private_key);

        $service->refresh($admin, $server);

        $this->assertDatabaseHas('luanti_servers', [
            'id' => $server->id, 'hetzner_status' => 'deleted', 'provisioning_private_key' => null,
            'provisioning_public_key' => null, 'hetzner_ssh_key_id' => null, 'hetzner_create_payload' => null,
        ]);
        Http::assertSentCount(5);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE' && $request->url() === 'https://api.hetzner.cloud/v1/ssh_keys/12');
    }

    public function test_failed_key_cleanup_retains_private_key_and_can_be_retried(): void
    {
        $admin = User::factory()->globalAdmin()->create();
        $server = $this->pendingServer();
        Http::preventStrayRequests();
        $this->fakeRegistration();
        app(HetznerProvisioningKey::class)->ensureRegistered($server);
        $server->forceFill(['hetzner_status' => 'deleting', 'hetzner_server_id' => 42])->save();
        Http::fake([
            'https://api.hetzner.cloud/v1/servers/42' => Http::response([], 404),
            'https://api.hetzner.cloud/v1/ssh_keys/12' => Http::sequence()
                ->push(['ssh_key' => $this->remoteKey($server)])->push(['private-details'], 500)
                ->push(['ssh_key' => $this->remoteKey($server)])->pushStatus(204),
        ]);

        try {
            app(HetznerVirtualMachines::class)->refresh($admin, $server);
            $this->fail('Failed cleanup was treated as complete.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('hetzner', $exception->errors());
        }
        $this->assertNotNull($server->fresh()->provisioning_private_key);

        app(HetznerVirtualMachines::class)->refresh($admin, $server);

        $this->assertDatabaseHas('luanti_servers', ['id' => $server->id, 'hetzner_status' => 'deleted', 'provisioning_private_key' => null]);
        Http::assertSentCount(6);
    }

    public function test_cancelling_key_setup_cleans_registration_without_submitting_a_vm(): void
    {
        $admin = User::factory()->globalAdmin()->create();
        $server = $this->pendingServer();
        Http::preventStrayRequests();
        $this->fakeRegistration();
        app(HetznerProvisioningKey::class)->ensureRegistered($server);
        Http::fake(['https://api.hetzner.cloud/v1/ssh_keys/12' => Http::sequence()
            ->push(['ssh_key' => $this->remoteKey($server)])->pushStatus(204)]);

        app(HetznerVirtualMachines::class)->delete($admin, $server);

        $this->assertDatabaseHas('luanti_servers', ['id' => $server->id, 'hetzner_status' => 'deleted', 'provisioning_private_key' => null]);
        Http::assertSentCount(2);
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/servers'));
    }

    public function test_uncertain_vm_creation_cannot_be_resubmitted_as_key_setup(): void
    {
        $admin = User::factory()->globalAdmin()->create();
        $server = $this->pendingServer();
        $server->forceFill(['hetzner_status' => 'unknown'])->save();
        Http::preventStrayRequests();

        try {
            app(HetznerVirtualMachines::class)->retryKeySetup($admin, $server);
            $this->fail('Uncertain creation was resubmitted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('hetzner', $exception->errors());
        }

        Http::assertNothingSent();
        $this->assertDatabaseHas('luanti_servers', ['id' => $server->id, 'hetzner_status' => 'unknown']);
    }

    public function test_failed_vm_creation_keeps_key_until_explicit_cleanup_and_cannot_be_forgotten_locally(): void
    {
        $admin = User::factory()->globalAdmin()->create();
        $server = $this->pendingServer();
        Http::preventStrayRequests();
        $this->fakeRegistration();
        app(HetznerProvisioningKey::class)->ensureRegistered($server);
        $server->forceFill(['hetzner_status' => 'create_failed'])->save();
        Http::fake(['https://api.hetzner.cloud/v1/ssh_keys/12' => Http::sequence()->push(['ssh_key' => $this->remoteKey($server)])->pushStatus(204)]);

        try {
            app(LuantiServerRegistry::class)->delete($admin, $server);
            $this->fail('Managed key was abandoned by local deletion.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('server', $exception->errors());
        }

        app(HetznerVirtualMachines::class)->delete($admin, $server);

        $this->assertDatabaseHas('luanti_servers', ['id' => $server->id, 'hetzner_status' => 'deleted', 'provisioning_private_key' => null]);
        Http::assertSentCount(2);
    }
}
