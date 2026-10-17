<?php

namespace App\Services;

use App\Models\LuantiServer;
use Illuminate\Validation\ValidationException;

class PublicHttpsProvisioning
{
    public function __construct(private LuantiSshClient $ssh, private BridgeHttpClient $http) {}

    public function configure(LuantiServer $server): void
    {
        $configuration = $server->public_https_configuration;
        $payload = ['reference' => $server->hetzner_reference, 'hostname' => $server->public_hostname,
            'ipv4' => $server->dns_ipv4, 'email' => $configuration['acme_email']];
        $script = 'PAYLOAD = __import__("json").loads(__import__("base64").b64decode("'.base64_encode(json_encode($payload, JSON_THROW_ON_ERROR)).'"))'."\n"
            .file_get_contents(resource_path('provisioning/install_https.py'));
        $server->forceFill(['https_status' => 'configuring', 'https_error' => null])->save();
        $result = json_decode($this->ssh->execute($server, $script, 900, 65536), true, flags: JSON_THROW_ON_ERROR);
        if (($result['status'] ?? null) === 'dns_pending') {
            $server->forceFill(['https_status' => 'waiting_dns'])->save();
            throw new PublicEndpointPendingException('Die öffentliche DNS-Auflösung zeigt noch nicht eindeutig auf diese VM.');
        }
        if (($result['status'] ?? null) === 'certificate_pending') {
            throw new PublicEndpointPendingException('Zertifikat noch nicht verfügbar. DNS und Erreichbarkeit von Port 80 prüfen.');
        }
        if (($result['status'] ?? null) === 'retry_pending') {
            throw new PublicEndpointPendingException('Die VM-Einrichtung ist vorübergehend fehlgeschlagen und wird erneut versucht.');
        }
        if (($result['status'] ?? null) !== 'ready' || ($result['hostname'] ?? null) !== $server->public_hostname
            || ($result['reference'] ?? null) !== $server->hetzner_reference) {
            throw ValidationException::withMessages(['https' => 'Die HTTPS-Einrichtung wurde nicht bestätigt. Private VM-Diagnose prüfen und erneut versuchen.']);
        }
        $server->forceFill(['https_status' => 'verifying'])->save();
        $this->verify($server);
        $server->forceFill(['https_status' => 'ready', 'https_error' => null, 'public_provisioning_next_at' => null])->save();
    }

    public function verify(LuantiServer $server): void
    {
        $url = 'https://'.$server->public_hostname.'/v1/lifecycle/jobs/install-check-'.bin2hex(random_bytes(12));
        if ($this->http->send('GET', $url, '', 10)->status() !== 401) {
            throw new PublicEndpointPendingException('Die öffentliche API hat ihren Zugriffsschutz noch nicht bestätigt.');
        }
        $response = $this->http->send('GET', $url, $server->api_token, 10);
        if ($response->status() !== 404 || $response->json('version') !== 1 || $response->json('error.code') !== 'unknown_job') {
            throw new PublicEndpointPendingException('Die öffentliche API hat ihre authentifizierte HTTPS-Prüfung noch nicht bestanden.');
        }
    }

    public static function retryDelay(int $attempts): int
    {
        return min(900, 30 * 2 ** max(0, min($attempts - 1, 5)));
    }
}
