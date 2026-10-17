<?php

namespace Tests\Feature;

use App\Models\LuantiServer;
use App\Models\User;
use App\Services\BridgeDevelopment;
use App\Services\BridgeHttpClient;
use App\Services\HetznerVirtualMachines;
use App\Services\LuantiSshClient;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class BridgeDevelopmentTest extends TestCase
{
    use RefreshDatabase;

    private string $fixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixture = storage_path('framework/testing/bridge-'.bin2hex(random_bytes(8)));
        File::ensureDirectoryExists($this->fixture.'/tools');
        File::put($this->fixture.'/tools/development.py', '# Test fixture');
        File::put($this->fixture.'/game.conf', 'name = Minetest Game');
        config(['services.bridge_development.ca_certificate' => $this->fixture.'/ca.crt']);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->fixture);
        parent::tearDown();
    }

    public function test_remote_deployment_is_disabled_outside_local_environment(): void
    {
        $this->app['env'] = 'production';
        Process::preventStrayProcesses();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('APP_ENV=local');
        app(BridgeDevelopment::class)->deploy('test', 1, $this->fixture, $this->fixture);
    }

    public function test_deployment_saves_encrypted_token_and_requires_an_exact_successful_test_receipt(): void
    {
        $this->app['env'] = 'local';
        User::factory()->globalAdmin()->create();
        $image = 'sha256:'.str_repeat('a', 64);
        $source = str_repeat('b', 64);
        $game = str_repeat('c', 64);
        $server = LuantiServer::factory()->hetznerVm()->create(['luanti_installation_status' => 'verified', 'luanti_image_id' => $image]);
        $receipt = storage_path('app/private/bridge-development/server-'.$server->id.'-tested.json');
        $previous = is_file($receipt) ? file_get_contents($receipt) : null;
        File::delete($receipt);
        $this->mock(HetznerVirtualMachines::class)->shouldReceive('refresh')->times(3)->andReturn($server);
        Process::preventStrayProcesses();
        Process::fake(function (PendingProcess $process) use ($source, $game): mixed {
            $path = $process->command[array_search('--output', $process->command, true) + 1];
            File::put($path, 'Fictitious package');

            return Process::result(json_encode(in_array('bundle', $process->command, true)
                ? ['sha256' => $source, 'archive_sha256' => str_repeat('d', 64)]
                : ['archive_sha256' => $game]));
        });
        $token = str_repeat('x', 43);
        $ssh = $this->mock(LuantiSshClient::class);
        $ssh->shouldReceive('execute')->once()->withArgs(fn (LuantiServer $vm, string $script, int $timeout, int $limit): bool => $vm->id === $server->id
            && str_contains($script, 'StrictHostKeyChecking') === false && $timeout === 1800 && $limit === 65536)
            ->andReturn(json_encode(['ok' => true, 'source_sha256' => $source, 'game_sha256' => $game, 'image_id' => $image]));
        $ssh->shouldReceive('execute')->once()->andReturn(json_encode(['ok' => true, 'source_sha256' => $source, 'image_id' => $image,
            'token' => $token, 'ca_certificate' => "-----BEGIN CERTIFICATE-----\nFictitious public certificate\n-----END CERTIFICATE-----"]));
        try {
            try {
                app(BridgeDevelopment::class)->deploy('up', $server->id, $this->fixture, $this->fixture);
                $this->fail('Untested snapshot was deployed.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('exact source', $exception->getMessage());
            }
            app(BridgeDevelopment::class)->deploy('test', $server->id, $this->fixture, $this->fixture);
            $result = app(BridgeDevelopment::class)->deploy('up', $server->id, $this->fixture, $this->fixture);
            $this->assertArrayNotHasKey('token', $result);
            $this->assertArrayNotHasKey('ca_certificate', $result);
            $this->assertSame($token, $server->fresh()->api_token);
            $this->assertNotSame($token, $server->fresh()->getRawOriginal('api_token'));
            $this->assertSame('https://localhost:18443', $server->fresh()->api_base_url);
        } finally {
            $previous === null ? File::delete($receipt) : File::put($receipt, $previous);
        }
    }

    /** @return array<string, array{string, string, bool}> */
    public static function certificateOrigins(): array
    {
        return [
            'local exact origin' => ['local', 'https://localhost:18443/v1/status', true],
            'production loopback' => ['production', 'https://localhost:18443/v1/status', false],
            'different port' => ['local', 'https://localhost:18444/v1/status', false],
            'remote hostname' => ['local', 'https://bridge.example.test:18443/v1/status', false],
            'userinfo' => ['local', 'https://someone@localhost:18443/v1/status', false],
            'http' => ['local', 'http://localhost:18443/v1/status', false],
        ];
    }

    #[DataProvider('certificateOrigins')]
    public function test_custom_ca_is_scoped_to_the_exact_local_development_origin(string $environment, string $url, bool $expected): void
    {
        $this->app['env'] = $environment;
        $certificate = $this->fixture.'/ca.crt';
        File::put($certificate, 'Fictitious public test CA');
        $handler = function ($request, array $options) use ($certificate, $expected): mixed {
            $this->assertSame($expected ? $certificate : true, $options['verify']);
            $options['sink']->write('{}');

            return Create::promiseFor(new Response(200, [], $options['sink']));
        };
        $client = new class($handler) extends BridgeHttpClient
        {
            public function __construct(private mixed $handler) {}

            protected function transportHandler(): callable
            {
                return $this->handler;
            }
        };
        $this->assertSame('{}', $client->send('GET', $url, 'test-token', 2)->body());
    }
}
