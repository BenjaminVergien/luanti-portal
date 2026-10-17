<?php

namespace Tests\Feature;

use App\Jobs\ProvisionLuantiBridge;
use App\Models\LuantiServer;
use App\Models\User;
use App\Services\BridgeProvisioning;
use App\Services\GithubBridgeSource;
use App\Services\HetznerDns;
use App\Services\HetznerVirtualMachines;
use App\Services\LuantiDockerInstallation;
use App\Services\LuantiImageCatalog;
use App\Services\LuantiSshClient;
use App\Services\PortalSettings;
use App\Services\PublicHttpsProvisioning;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class BridgeProvisioningTest extends TestCase
{
    use RefreshDatabase;

    private array $artifacts = [];

    protected function tearDown(): void
    {
        foreach ($this->artifacts as $digest) {
            File::delete(storage_path('app/private/bridge-distribution/'.$digest.'.zip'));
        }
        parent::tearDown();
    }

    private function prepare(User $actor): array
    {
        Cache::forever(LuantiImageCatalog::CacheKey, ['images' => ['ghcr.io/luanti-org/luanti:5.17.0' => '5.17.0'], 'fetched_at' => now()->timestamp]);
        app(PortalSettings::class)->save($actor, ['bridge_enabled' => true, 'luanti_docker_image' => 'ghcr.io/luanti-org/luanti:5.17.0']);
        $bytes = 'source-fixture-'.bin2hex(random_bytes(8));
        $game = 'game-fixture-'.bin2hex(random_bytes(8));
        $this->artifacts = [hash('sha256', $bytes), hash('sha256', $game)];
        $this->mock(GithubBridgeSource::class)->shouldReceive('game')->once()->andReturn($game)
            ->shouldReceive('resolve')->once()->with('Stewie23/luanti-connect', 'main')->andReturn(str_repeat('a', 40))
            ->shouldReceive('bundle')->once()->with('Stewie23/luanti-connect', str_repeat('a', 40))
            ->andReturn(['bytes' => $bytes, 'source_sha256' => str_repeat('b', 64)]);

        return app(BridgeProvisioning::class)->prepare($actor);
    }

    private function server(User $actor): LuantiServer
    {
        $prepared = $this->prepare($actor);

        return LuantiServer::factory()->hetznerVm()->create(['bridge_configuration' => $prepared['configuration'],
            'bridge_commit' => $prepared['commit'], 'bridge_status' => 'pending', 'bridge_requested_by' => $actor->id,
            'luanti_installation_status' => 'verified', 'luanti_image_id' => 'sha256:'.str_repeat('c', 64)]);
    }

    public function test_success_stores_encrypted_api_token_and_pinned_commit_without_sending_github_credentials_to_vm(): void
    {
        $actor = User::factory()->globalAdmin()->create();
        app(PortalSettings::class)->save($actor, ['bridge_github_token' => 'fictitious-github-secret']);
        $server = $this->server($actor);
        $options = ['private_key_bits' => 2048];
        if (is_file(dirname(PHP_BINARY).'/extras/ssl/openssl.cnf')) {
            $options['config'] = dirname(PHP_BINARY).'/extras/ssl/openssl.cnf';
        }
        $key = openssl_pkey_new($options);
        $csr = openssl_csr_new(['commonName' => 'fictitious-test-ca'], $key, $options);
        openssl_x509_export(openssl_csr_sign($csr, null, $key, 1, $options), $certificate);
        $this->mock(HetznerVirtualMachines::class)->shouldReceive('refresh')->once()->andReturn($server);
        $this->mock(LuantiSshClient::class)->shouldReceive('execute')->once()->withArgs(function (LuantiServer $vm, string $script) use ($server): bool {
            $this->assertStringNotContainsString('fictitious-github-secret', $script);
            preg_match('/b64decode\("([A-Za-z0-9+\/=]+)"\)/', $script, $matches);
            $payload = json_decode(base64_decode($matches[1]), true);
            $this->assertArrayNotHasKey('github_token', $payload);
            $this->assertSame(str_repeat('a', 40), $payload['commit']);

            return $vm->id === $server->id;
        })->andReturn(json_encode(['ok' => true, 'commit' => $server->bridge_commit, 'source_sha256' => str_repeat('b', 64),
            'image_id' => $server->luanti_image_id, 'token' => str_repeat('t', 43), 'ca_certificate' => $certificate]));
        $this->assertFalse(app(BridgeProvisioning::class)->process($server->id));
        $server->refresh();
        $this->assertSame('installed', $server->bridge_status);
        $this->assertSame(str_repeat('t', 43), $server->api_token);
        $this->assertNotSame($server->api_token, $server->getRawOriginal('api_token'));
        $this->assertNotSame(json_encode($server->bridge_configuration), $server->getRawOriginal('bridge_configuration'));
        $this->assertNotNull($server->bridge_installed_at);
        $this->assertArrayNotHasKey('bridge_ca_certificate', $server->toArray());
        $this->assertFalse(app(BridgeProvisioning::class)->process($server->id));
    }

    public function test_failed_installation_preserves_snapshot_and_retry_uses_same_commit(): void
    {
        Queue::fake();
        $actor = User::factory()->globalAdmin()->create();
        $server = $this->server($actor);
        $this->mock(HetznerVirtualMachines::class)->shouldReceive('refresh')->once()->andReturn($server);
        $this->mock(LuantiSshClient::class)->shouldReceive('execute')->once()->andReturn('{"ok":false,"error":"private-details"}');
        app(BridgeProvisioning::class)->process($server->id);
        $this->assertSame('failed', $server->fresh()->bridge_status);
        $this->assertStringNotContainsString('private-details', $server->fresh()->bridge_error);
        app(BridgeProvisioning::class)->retry($actor, $server);
        $this->assertSame('pending', $server->fresh()->bridge_status);
        $this->assertSame(str_repeat('a', 40), $server->fresh()->bridge_commit);
        Queue::assertPushed(ProvisionLuantiBridge::class, fn ($job): bool => $job->serverId === $server->id);
    }

    public function test_waiting_engine_is_requeued_but_deleted_vm_is_never_contacted(): void
    {
        $actor = User::factory()->globalAdmin()->create();
        $server = $this->server($actor);
        $server->forceFill(['luanti_installation_status' => 'pending'])->save();
        $this->mock(LuantiDockerInstallation::class)->shouldReceive('verify')->once()->andReturn(['successful' => false]);
        $this->mock(LuantiSshClient::class)->shouldNotReceive('execute');
        $this->assertTrue(app(BridgeProvisioning::class)->process($server->id));
        $server->forceFill(['hetzner_status' => 'deleted'])->save();
        $this->assertFalse(app(BridgeProvisioning::class)->process($server->id));
    }

    public function test_revoked_admin_cannot_install_even_when_job_was_previously_queued(): void
    {
        $actor = User::factory()->globalAdmin()->create();
        $server = $this->server($actor);
        $actor->forceFill(['internal_role' => null])->save();
        $this->mock(LuantiSshClient::class)->shouldNotReceive('execute');
        $this->assertFalse(app(BridgeProvisioning::class)->process($server->id));
        $this->assertSame('failed', $server->fresh()->bridge_status);
    }

    public function test_missing_game_or_github_access_prevents_paid_vm_creation(): void
    {
        $actor = User::factory()->globalAdmin()->create();
        config(['services.hetzner.token' => 'test-project-token']);
        Cache::forever(LuantiImageCatalog::CacheKey, ['images' => ['ghcr.io/luanti-org/luanti:5.17.0' => '5.17.0'], 'fetched_at' => now()->timestamp]);
        app(PortalSettings::class)->save($actor, ['bridge_enabled' => true, 'luanti_docker_image' => 'ghcr.io/luanti-org/luanti:5.17.0', 'bridge_game_source' => 'nonexistent-game-fixture']);
        Http::preventStrayRequests();
        try {
            app(HetznerVirtualMachines::class)->create($actor, ['name' => 'test-vm', 'server_type' => 'cx23', 'image' => 'ubuntu-24.04', 'location' => 'nbg1']);
            $this->fail('Created a paid VM without its game content.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('luanti_servers', 0);
            Http::assertNothingSent();
        }
    }

    public function test_public_installation_uses_production_hostname_and_keeps_api_unpublished_until_https_is_verified(): void
    {
        $actor = User::factory()->globalAdmin()->create();
        $server = $this->server($actor);
        $server->forceFill(['public_hostname' => 'amber-otter.bensfoundry.de',
            'public_https_configuration' => ['zone_id' => 99, 'zone_name' => 'bensfoundry.de', 'label' => 'amber-otter', 'acme_email' => 'admin@example.test']])->save();
        $this->mock(HetznerVirtualMachines::class)->shouldReceive('refresh')->once()->andReturn($server);
        $this->mock(HetznerDns::class)->shouldReceive('ensureRecord')->once();
        $this->mock(PublicHttpsProvisioning::class)->shouldNotReceive('configure');
        $this->mock(LuantiSshClient::class)->shouldReceive('execute')->once()->withArgs(function (LuantiServer $vm, string $script): bool {
            preg_match('/b64decode\("([A-Za-z0-9+\/=]+)"\)/', $script, $matches);
            $payload = json_decode(base64_decode($matches[1]), true);
            $this->assertSame('amber-otter.bensfoundry.de', $payload['hostname']);
            $this->assertArrayNotHasKey('dns_api_token', $payload);

            return true;
        })->andReturn(json_encode(['ok' => true, 'commit' => $server->bridge_commit, 'source_sha256' => str_repeat('b', 64),
            'image_id' => $server->luanti_image_id, 'token' => str_repeat('t', 43), 'ca_certificate' => null]));
        $this->assertTrue(app(BridgeProvisioning::class)->process($server->id));
        $this->assertSame(str_repeat('t', 43), $server->fresh()->api_token);
        $this->assertNotNull($server->fresh()->bridge_installed_at);
        $this->assertNull($server->fresh()->api_base_url);
        $this->assertSame('installing', $server->fresh()->bridge_status);
    }
}
