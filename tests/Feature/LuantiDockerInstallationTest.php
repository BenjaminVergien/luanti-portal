<?php

namespace Tests\Feature;

use App\Filament\Pages\LuantiServers;
use App\Models\LuantiServer;
use App\Models\User;
use App\Services\HetznerVirtualMachines;
use App\Services\LuantiDockerInstallation;
use App\Services\LuantiImageCatalog;
use App\Services\LuantiSshClient;
use App\Services\PortalSettings;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LuantiDockerInstallationTest extends TestCase
{
    use RefreshDatabase;

    private function installationServer(): LuantiServer
    {
        return LuantiServer::factory()->hetznerVm()->create([
            'luanti_docker_image' => 'ghcr.io/luanti-org/luanti:5.17.0', 'luanti_installation_status' => 'pending',
            'provisioning_host_public_key' => 'ssh-rsa test-host-key',
        ]);
    }

    private function fakeVm(LuantiServer $server, string $status = 'running'): void
    {
        Http::preventStrayRequests();
        config(['services.hetzner.token' => 'project-token']);
        Http::fake(['https://api.hetzner.cloud/v1/servers/42' => Http::response(['server' => [
            'id' => 42, 'status' => $status, 'public_net' => ['ipv4' => ['ip' => '192.0.2.42']],
            'labels' => ['managed-by' => 'luanti-portal', 'portal-reference' => $server->hetzner_reference],
        ]])]);
    }

    /** @return array{version: int, reference: string, image: string, status: string, image_id: string, engine_version: string} */
    private function verifiedResult(LuantiServer $server): array
    {
        return ['version' => 1, 'reference' => $server->hetzner_reference, 'image' => $server->luanti_docker_image,
            'status' => 'verified', 'image_id' => 'sha256:'.str_repeat('a', 64), 'engine_version' => 'Luanti 5.17.0 (Linux)'];
    }

    public function test_vm_creation_snapshots_image_and_supplies_encrypted_bootstrap_with_separate_host_identity(): void
    {
        $admin = User::factory()->globalAdmin()->create();
        config(['services.hetzner.token' => 'project-token']);
        Cache::forever(LuantiImageCatalog::CacheKey, ['images' => ['ghcr.io/luanti-org/luanti:5.17.0' => '5.17.0'], 'fetched_at' => now()->timestamp]);
        app(PortalSettings::class)->save($admin, ['luanti_docker_image' => 'ghcr.io/luanti-org/luanti:5.17.0']);
        Http::preventStrayRequests();
        Http::fake([
            'https://api.hetzner.cloud/v1/ssh_keys?*' => Http::response(['ssh_keys' => []]),
            'https://api.hetzner.cloud/v1/ssh_keys' => fn (Request $request) => Http::response(['ssh_key' => [
                'id' => 12, 'name' => $request['name'], 'public_key' => $request['public_key'],
            ]], 201),
            'https://api.hetzner.cloud/v1/servers' => fn (Request $request) => Http::response(['server' => [
                'id' => 42, 'status' => 'initializing', 'labels' => $request['labels'],
            ]], 201),
        ]);

        $server = app(HetznerVirtualMachines::class)->create($admin, ['name' => 'engine-test', 'server_type' => 'cx23', 'location' => 'nbg1', 'image' => 'ubuntu-24.04']);

        $cloudConfig = $server->provisioning_cloud_config;
        $cloud = json_decode(substr($cloudConfig, strlen("#cloud-config\n")), true, flags: JSON_THROW_ON_ERROR);
        $this->assertLessThanOrEqual(32768, strlen($cloudConfig));
        $this->assertSame($server->provisioning_host_public_key, $cloud['ssh_keys']['rsa_public']);
        $this->assertNotSame($server->provisioning_private_key, $cloud['ssh_keys']['rsa_private']);
        $this->assertNotSame($cloudConfig, $server->getRawOriginal('provisioning_cloud_config'));
        $this->assertStringNotContainsString('PRIVATE KEY', $server->toJson());
        $configuration = json_decode($cloud['write_files'][0]['content'], true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(['reference' => $server->hetzner_reference, 'image' => 'ghcr.io/luanti-org/luanti:5.17.0'], $configuration);
        $this->assertSame([['python3', '/usr/local/lib/luanti-portal/install.py']], $cloud['runcmd']);
        $this->assertDatabaseHas('luanti_servers', ['id' => $server->id, 'luanti_installation_status' => 'pending', 'luanti_docker_image' => 'ghcr.io/luanti-org/luanti:5.17.0']);
        app(PortalSettings::class)->save($admin, ['luanti_docker_image' => null]);
        $this->assertSame('ghcr.io/luanti-org/luanti:5.17.0', $server->fresh()->luanti_docker_image);
        Http::assertSentCount(3);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && $request->url() === 'https://api.hetzner.cloud/v1/servers'
            && ($request['user_data'] ?? null) === $cloudConfig);
    }

    public function test_successful_verification_records_actual_image_identity_and_engine_version(): void
    {
        $admin = User::factory()->globalAdmin()->create();
        $server = $this->installationServer();
        $this->freezeTime();
        $this->fakeVm($server);
        $this->mock(LuantiSshClient::class)->shouldReceive('execute')->once()
            ->withArgs(fn (LuantiServer $actual, string $script): bool => $actual->id === $server->id && str_contains($script, "'--version'"))
            ->andReturn(json_encode($this->verifiedResult($server), JSON_THROW_ON_ERROR));

        $result = app(LuantiDockerInstallation::class)->verify($admin, $server);

        $this->assertTrue($result['successful']);
        $this->assertDatabaseHas('luanti_servers', ['id' => $server->id, 'luanti_installation_status' => 'verified',
            'luanti_engine_version' => 'Luanti 5.17.0 (Linux)', 'luanti_image_id' => 'sha256:'.str_repeat('a', 64)]);
        $this->assertSame(now()->timestamp, $server->fresh()->luanti_verified_at->timestamp);
        Http::assertSentCount(1);
    }

    public function test_automatic_installation_rejects_unsupported_operating_system_before_creating_resources(): void
    {
        $admin = User::factory()->globalAdmin()->create();
        config(['services.hetzner.token' => 'project-token']);
        $this->mock(PortalSettings::class)->shouldReceive('luantiDockerImage')->once()->andReturn('ghcr.io/luanti-org/luanti:5.17.0');
        Http::preventStrayRequests();

        try {
            app(HetznerVirtualMachines::class)->create($admin, ['name' => 'engine-test', 'server_type' => 'cx23', 'location' => 'nbg1', 'image' => 'debian-12']);
            $this->fail('Unsupported operating system was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('image', $exception->errors());
        }

        $this->assertDatabaseCount('luanti_servers', 0);
        Http::assertNothingSent();
    }

    public function test_verification_refuses_provider_vm_with_mismatched_ownership_before_ssh(): void
    {
        $admin = User::factory()->globalAdmin()->create();
        $server = $this->installationServer();
        config(['services.hetzner.token' => 'project-token']);
        Http::preventStrayRequests();
        Http::fake(['https://api.hetzner.cloud/v1/servers/42' => Http::response(['server' => [
            'id' => 42, 'status' => 'running', 'public_net' => ['ipv4' => ['ip' => '192.0.2.42']],
            'labels' => ['managed-by' => 'luanti-portal', 'portal-reference' => 'another-vm'],
        ]])]);
        $this->mock(LuantiSshClient::class)->shouldNotReceive('execute');

        try {
            app(LuantiDockerInstallation::class)->verify($admin, $server);
            $this->fail('Unowned VM was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('hetzner', $exception->errors());
        }

        $this->assertDatabaseHas('luanti_servers', ['id' => $server->id, 'luanti_verified_at' => null]);
        Http::assertSentCount(1);
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function verificationResponses(): array
    {
        return [
            'waiting for cloud-init' => [['version' => 1, 'status' => 'waiting'], 'pending'],
            'still installing' => [['status' => 'installing'], 'installing'],
            'bootstrap failed' => [['status' => 'failed', 'error' => 'private-details'], 'failed'],
            'wrong image' => [['image' => 'evil/image:tag'], 'failed'],
            'wrong VM' => [['reference' => 'other-vm'], 'failed'],
            'invalid image id' => [['image_id' => 'invalid'], 'failed'],
            'invalid engine version' => [['engine_version' => '<script>private-details</script>'], 'failed'],
            'version with HTML' => [['engine_version' => 'Luanti 5.17.0 <script>private-details</script>'], 'failed'],
            'bootstrap installed marker alone is insufficient' => [['status' => 'installed'], 'failed'],
            'bad contract' => [['version' => 2], 'failed'],
        ];
    }

    #[DataProvider('verificationResponses')]
    public function test_unverified_results_never_report_success_and_clear_old_verified_timestamp(array $changes, string $state): void
    {
        $admin = User::factory()->globalAdmin()->create();
        $server = $this->installationServer();
        $server->forceFill(['luanti_verified_at' => now(), 'luanti_installation_status' => 'verified'])->save();
        $data = ($changes['status'] ?? null) === 'waiting' ? $changes : [...$this->verifiedResult($server), ...$changes];
        $this->fakeVm($server);
        $this->mock(LuantiSshClient::class)->shouldReceive('execute')->once()->andReturn(json_encode($data, JSON_THROW_ON_ERROR));

        $result = app(LuantiDockerInstallation::class)->verify($admin, $server);

        $this->assertFalse($result['successful']);
        $this->assertDatabaseHas('luanti_servers', ['id' => $server->id, 'luanti_installation_status' => $state, 'luanti_verified_at' => null]);
        $this->assertStringNotContainsString('private-details', json_encode($result, JSON_THROW_ON_ERROR));
        Http::assertSentCount(1);
    }

    public function test_ssh_not_ready_is_reported_without_marking_installation_verified(): void
    {
        $admin = User::factory()->globalAdmin()->create();
        $server = $this->installationServer();
        $this->fakeVm($server);
        $this->mock(LuantiSshClient::class)->shouldReceive('execute')->once()
            ->andThrow(ValidationException::withMessages(['installation' => 'SSH noch nicht erreichbar.']));

        $result = app(LuantiDockerInstallation::class)->verify($admin, $server);

        $this->assertFalse($result['successful']);
        $this->assertDatabaseHas('luanti_servers', ['id' => $server->id, 'luanti_verified_at' => null, 'luanti_installation_status' => 'pending']);
        Http::assertSentCount(1);
    }

    public function test_inactive_vm_is_not_contacted_over_ssh(): void
    {
        $admin = User::factory()->globalAdmin()->create();
        $server = $this->installationServer();
        $this->fakeVm($server, 'off');
        $this->mock(LuantiSshClient::class)->shouldNotReceive('execute');

        $result = app(LuantiDockerInstallation::class)->verify($admin, $server);

        $this->assertFalse($result['successful']);
        $this->assertDatabaseHas('luanti_servers', ['id' => $server->id, 'luanti_verified_at' => null]);
        Http::assertSentCount(1);
    }

    public function test_verification_action_displays_success_without_returning_bootstrap_secrets(): void
    {
        $admin = User::factory()->globalAdmin()->create();
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::bootCurrentPanel();
        Filament::setTenant(null);
        $server = $this->installationServer();
        $server->forceFill(['provisioning_cloud_config' => 'private-bootstrap-secret'])->save();
        $this->fakeVm($server);
        $this->mock(LuantiSshClient::class)->shouldReceive('execute')->once()->andReturn(json_encode($this->verifiedResult($server), JSON_THROW_ON_ERROR));

        $page = Livewire::test(LuantiServers::class)->callAction(TestAction::make('verifyInstallation')->table($server))
            ->assertNotified('Luanti-Engine geprüft');

        $payload = json_encode([$page->snapshot, $page->effects], JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('private-bootstrap-secret', $payload);
        $this->assertStringNotContainsString($server->getRawOriginal('provisioning_cloud_config'), $payload);
        $this->assertDatabaseHas('luanti_servers', ['id' => $server->id, 'luanti_installation_status' => 'verified']);
        Http::assertSentCount(1);
    }

    public function test_non_admin_cannot_trigger_verification(): void
    {
        $actor = User::factory()->caseworker()->create();
        $server = $this->installationServer();
        Http::preventStrayRequests();
        $this->mock(LuantiSshClient::class)->shouldNotReceive('execute');

        try {
            app(LuantiDockerInstallation::class)->verify($actor, $server);
            $this->fail('Unauthorized installation verification was accepted.');
        } catch (AuthorizationException) {
            Http::assertNothingSent();
        }
    }
}
