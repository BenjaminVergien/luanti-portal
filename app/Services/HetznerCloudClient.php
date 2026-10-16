<?php

namespace App\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

class HetznerCloudClient
{
    public function __construct(private HetznerSettings $settings) {}

    public function assertConfigured(): void
    {
        if (! $this->settings->hasToken()) {
            throw ValidationException::withMessages(['hetzner' => 'Der Hetzner-API-Zugang ist noch nicht konfiguriert.']);
        }
    }

    /** @param array<string, mixed> $data */
    public function request(string $method, string $path, array $data = []): Response
    {
        $this->assertConfigured();

        return Http::withToken($this->settings->token())->acceptJson()->asJson()
            ->connectTimeout(5)->timeout(20)->withoutRedirecting()
            ->send($method, 'https://api.hetzner.cloud/v1/'.$path, $data === [] ? [] : [
                $method === 'GET' ? 'query' : 'json' => $data,
            ]);
    }
}
