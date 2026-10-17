<?php

namespace App\Services;

use App\Models\LuantiServer;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use SensitiveParameter;
use Throwable;

class ProvisionedBridgeTransport
{
    public function __construct(private LuantiSshClient $ssh) {}

    public static function origin(LuantiServer $server): string
    {
        return 'https://'.$server->hetzner_reference.'.bridge.invalid';
    }

    public function handles(string $url): bool
    {
        return str_ends_with(strtolower(parse_url($url, PHP_URL_HOST) ?: ''), '.bridge.invalid');
    }

    public function send(string $method, string $url, #[SensitiveParameter] string $token, int $timeout, #[SensitiveParameter] ?string $body): Response
    {
        $parts = parse_url($url);
        if (! is_array($parts) || ($parts['scheme'] ?? null) !== 'https' || array_intersect(['user', 'pass', 'port', 'query', 'fragment'], array_keys($parts)) !== []
            || ! in_array($method, ['GET', 'POST'], true) || ! str_starts_with($parts['path'] ?? '', '/v1/') || strlen($url) > 2048) {
            throw new ConnectionException('Invalid managed bridge endpoint.');
        }
        $server = LuantiServer::query()->where('api_base_url', 'https://'.$parts['host'])->first();
        if ($server === null || self::origin($server) !== $server->api_base_url || $server->bridge_status !== 'installed'
            || $server->hetzner_status !== 'running' || blank($server->bridge_ca_certificate)) {
            throw new ConnectionException('The managed bridge is not available.');
        }
        $payload = ['method' => $method, 'path' => $parts['path'], 'token' => $token, 'body' => $body,
            'timeout' => max(1, min($timeout, 60)), 'ca_certificate' => $server->bridge_ca_certificate];
        $script = 'PAYLOAD = __import__("json").loads(__import__("base64").b64decode("'.base64_encode(json_encode($payload, JSON_THROW_ON_ERROR)).'"))'."\n"
            .file_get_contents(resource_path('provisioning/bridge_request.py'));
        try {
            $result = json_decode($this->ssh->execute($server, $script, min($timeout, 60) + 15, 400000), true, flags: JSON_THROW_ON_ERROR);
            if (($result['too_large'] ?? false) === true) {
                throw new BridgeResponseTooLargeException;
            }
            $bytes = is_string($result['body'] ?? null) ? base64_decode($result['body'], true) : false;
            if (($result['ok'] ?? false) !== true || ! is_int($result['status'] ?? null) || $result['status'] < 100 || $result['status'] > 599 || $bytes === false) {
                throw new ConnectionException('Managed bridge response not confirmed.');
            }
            if (strlen($bytes) > BridgeHttpClient::MAX_RESPONSE_BYTES) {
                throw new BridgeResponseTooLargeException;
            }

            return new Response(new PsrResponse($result['status'], ['Content-Type' => 'application/json'], $bytes));
        } catch (BridgeResponseTooLargeException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new ConnectionException('Managed bridge connection failed.');
        }
    }
}
