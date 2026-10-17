<?php

namespace Tests\Feature;

use App\Models\LuantiServer;
use App\Services\BridgeHttpClient;
use App\Services\BridgeResponseTooLargeException;
use App\Services\LuantiSshClient;
use App\Services\ProvisionedBridgeTransport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProvisionedBridgeTransportTest extends TestCase
{
    use RefreshDatabase;

    private function server(): LuantiServer
    {
        $server = LuantiServer::factory()->hetznerVm()->create(['bridge_status' => 'installed', 'bridge_ca_certificate' => 'test-public-ca']);
        $server->update(['api_base_url' => ProvisionedBridgeTransport::origin($server)]);

        return $server;
    }

    public function test_managed_request_reuses_pinned_ssh_without_public_dns_or_a_manual_tunnel_and_preserves_exact_job_bytes(): void
    {
        $server = $this->server();
        $body = "{\"version\":1, \"job_id\":\"fictitious-job\"}\n";
        Http::preventStrayRequests();
        $this->mock(LuantiSshClient::class)->shouldReceive('execute')->once()
            ->withArgs(function (LuantiServer $target, string $script, int $timeout, int $bound) use ($server, $body): bool {
                $this->assertSame($server->id, $target->id);
                preg_match('/b64decode\("([A-Za-z0-9+\/=]+)"\)/', $script, $matches);
                $payload = json_decode(base64_decode($matches[1]), true);
                $this->assertSame($body, $payload['body']);
                $this->assertSame('fictitious-token', $payload['token']);
                $this->assertSame('test-public-ca', $payload['ca_certificate']);
                $this->assertSame('/v1/jobs', $payload['path']);
                $this->assertStringContainsString('ssl.create_default_context', $script);
                $this->assertStringNotContainsString('CERT_NONE', $script);

                return $timeout === 23 && $bound === 400000;
            })->andReturn(json_encode(['ok' => true, 'status' => 202, 'body' => base64_encode('{"accepted":true}')], JSON_THROW_ON_ERROR));
        $response = app(BridgeHttpClient::class)->send('POST', $server->api_base_url.'/v1/jobs', 'fictitious-token', 8, $body);
        $this->assertSame(202, $response->status());
        $this->assertTrue($response->json('accepted'));
        Http::assertNothingSent();
    }

    public function test_wrong_api_token_is_forwarded_and_rejected_rather_than_replaced_by_stored_token(): void
    {
        $server = $this->server();
        $this->mock(LuantiSshClient::class)->shouldReceive('execute')->once()->andReturn(json_encode(['ok' => true, 'status' => 401, 'body' => base64_encode('{}')]));
        $this->assertSame(401, app(BridgeHttpClient::class)->send('GET', $server->api_base_url.'/v1/status', 'wrong-token', 8)->status());
    }

    public function test_deleted_vm_and_unknown_managed_origin_are_rejected_without_ssh(): void
    {
        $server = $this->server();
        $server->forceFill(['hetzner_status' => 'deleted'])->save();
        $this->mock(LuantiSshClient::class)->shouldNotReceive('execute');
        $this->expectException(ConnectionException::class);
        app(BridgeHttpClient::class)->send('GET', $server->api_base_url.'/v1/status', 'token', 8);
    }

    public function test_remote_tls_failure_is_sanitized(): void
    {
        $server = $this->server();
        $this->mock(LuantiSshClient::class)->shouldReceive('execute')->once()->andReturn('{"ok":false}');
        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('Managed bridge connection failed.');
        app(BridgeHttpClient::class)->send('GET', $server->api_base_url.'/v1/status', 'token', 8);
    }

    public function test_large_remote_response_is_rejected(): void
    {
        $server = $this->server();
        $this->mock(LuantiSshClient::class)->shouldReceive('execute')->once()->andReturn('{"too_large":true}');
        $this->expectException(BridgeResponseTooLargeException::class);
        app(BridgeHttpClient::class)->send('GET', $server->api_base_url.'/v1/status', 'token', 8);
    }
}
