<?php

namespace App\Services;

use App\Models\LuantiServer;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

class HetznerDns
{
    public function __construct(private PortalSettings $settings) {}

    /** @return array{id: int, name: string} */
    public function zone(?int $id = null, ?string $name = null): array
    {
        $name ??= $this->settings->publicHttps()['dns_zone'];
        $response = $this->request('GET', 'zones/'.rawurlencode((string) ($id ?? $name)));
        $zone = $response->json('zone');
        if (! is_array($zone) || ! is_int($zone['id'] ?? null) || $zone['id'] < 1 || ($id !== null && $zone['id'] !== $id)
            || ($zone['name'] ?? null) !== $name || ($zone['mode'] ?? null) !== 'primary') {
            throw ValidationException::withMessages(['dns' => 'Die bestehende primäre DNS-Zone ist mit diesem Token nicht verfügbar. Bitte das Projekt KonsoleH prüfen.']);
        }

        return ['id' => $zone['id'], 'name' => $zone['name']];
    }

    /** @param array{id: int, name: string} $zone */
    public function assertAvailable(array $zone, string $label): void
    {
        foreach (['A', 'AAAA', 'CNAME'] as $type) {
            if ($this->record($zone['id'], $label, $type) !== null) {
                throw ValidationException::withMessages(['name' => 'Für diesen Namen besteht bereits ein DNS-Eintrag. Bitte einen anderen VM-Namen wählen.']);
            }
        }
    }

    /** @return array{zone_id: int, zone_name: string, label: string, acme_email: string}|null */
    public function prepare(string $label): ?array
    {
        $settings = $this->settings->publicHttps();
        if (! $settings['public_https_enabled']) {
            return null;
        }
        if (! preg_match('/\A[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\z/', $label)
            || strlen($label.'.'.$settings['dns_zone']) > 253) {
            throw ValidationException::withMessages(['name' => 'Für öffentliches HTTPS einen einzelnen DNS-Namen mit höchstens 63 Zeichen verwenden, ohne Punkte.']);
        }
        if (! $this->settings->bridge()['bridge_enabled'] || $this->settings->luantiDockerImage() === null || blank($settings['acme_email'])) {
            throw ValidationException::withMessages(['dns' => 'Bitte zuerst Bridge, Luanti-Image und Zertifikat-E-Mail konfigurieren.']);
        }
        $zone = $this->zone();
        $hostname = $label.'.'.$zone['name'];
        if (LuantiServer::query()->where('public_hostname', $hostname)->orWhere('domain', $hostname)->exists()) {
            throw ValidationException::withMessages(['name' => 'Dieser Hostname ist bereits im Portal reserviert.']);
        }
        $this->assertAvailable($zone, $label);

        return ['zone_id' => $zone['id'], 'zone_name' => $zone['name'], 'label' => $label, 'acme_email' => $settings['acme_email']];
    }

    public function ensureRecord(LuantiServer $server): void
    {
        $configuration = $server->public_https_configuration;
        $this->zone($configuration['zone_id'], $configuration['zone_name']);
        $this->waitForAction($server);
        $this->assertNoAliasOrIpv6($configuration['zone_id'], $configuration['label']);
        if (! filter_var($server->hetzner_ipv4, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            throw new PublicEndpointPendingException('Die VM hat noch keine bestätigte IPv4-Adresse.');
        }
        $record = $this->record($configuration['zone_id'], $configuration['label'], 'A');
        if ($record !== null) {
            if (! $this->owned($server, $record) || $server->dns_ipv4 !== $server->hetzner_ipv4) {
                throw ValidationException::withMessages(['dns' => 'Der DNS-Eintrag gehört nicht eindeutig zu dieser VM oder wurde verändert. Es wurde nichts überschrieben.']);
            }
            $server->forceFill(['dns_status' => 'ready', 'dns_error' => null])->save();

            return;
        }
        $server->forceFill(['dns_status' => 'creating', 'dns_ipv4' => $server->hetzner_ipv4, 'dns_error' => null])->save();
        $response = $this->request('POST', 'zones/'.$configuration['zone_id'].'/rrsets', [
            'name' => $configuration['label'], 'type' => 'A', 'ttl' => 300,
            'records' => [['value' => $server->dns_ipv4, 'comment' => $this->owner($server)]],
        ]);
        $this->rememberAction($server, $response);
        throw new PublicEndpointPendingException('DNS-Eintrag angelegt; Bestätigung wird abgewartet.');
    }

    public function cleanup(LuantiServer $server): void
    {
        if ($server->hetzner_status !== 'deleted' || ! $server->dns_cleanup_pending) {
            return;
        }
        $configuration = $server->public_https_configuration;
        $this->zone($configuration['zone_id'], $configuration['zone_name']);
        $this->waitForAction($server);
        $record = $this->record($configuration['zone_id'], $configuration['label'], 'A');
        if ($record === null) {
            $server->forceFill(['dns_status' => 'deleted', 'dns_cleanup_pending' => false, 'dns_error' => null,
                'dns_cleanup_next_at' => null])->save();

            return;
        }
        if (! $this->owned($server, $record)) {
            throw ValidationException::withMessages(['dns' => 'DNS-Eintrag wurde verändert. Automatisches Löschen ist gesperrt.']);
        }
        $response = $this->request('DELETE', $this->recordPath($configuration['zone_id'], $configuration['label'], 'A'), allowMissing: true);
        $this->rememberAction($server, $response);
        throw new PublicEndpointPendingException('DNS-Löschung angefragt; Bestätigung wird abgewartet.');
    }

    private function owner(LuantiServer $server): string
    {
        return 'luanti-portal:'.$server->hetzner_reference;
    }

    /** @param array<string, mixed> $record */
    private function owned(LuantiServer $server, array $record): bool
    {
        $records = $record['records'] ?? null;

        return ($record['name'] ?? null) === $server->public_https_configuration['label'] && ($record['type'] ?? null) === 'A'
            && is_array($records) && count($records) === 1 && ($records[0]['value'] ?? null) === $server->dns_ipv4
            && ($records[0]['comment'] ?? null) === $this->owner($server);
    }

    private function assertNoAliasOrIpv6(int $zone, string $label): void
    {
        foreach (['AAAA', 'CNAME'] as $type) {
            if ($this->record($zone, $label, $type) !== null) {
                throw ValidationException::withMessages(['dns' => 'Ein vorhandener AAAA- oder CNAME-Eintrag verhindert die sichere HTTPS-Einrichtung.']);
            }
        }
    }

    private function waitForAction(LuantiServer $server): void
    {
        if ($server->dns_action_id === null) {
            return;
        }
        $response = $this->request('GET', 'zones/'.$server->public_https_configuration['zone_id'].'/actions/'.$server->dns_action_id);
        if ($response->json('action.status') === 'running') {
            throw new PublicEndpointPendingException('Hetzner verarbeitet die DNS-Anfrage noch.');
        }
        $server->forceFill(['dns_action_id' => null])->save();
        if ($response->json('action.status') !== 'success') {
            throw new PublicEndpointPendingException('Hetzner hat die DNS-Anfrage nicht bestätigt.');
        }
    }

    private function rememberAction(LuantiServer $server, Response $response): void
    {
        $id = $response->json('action.id');
        if (is_int($id) && $id > 0) {
            $server->forceFill(['dns_action_id' => $id])->save();
        }
    }

    private function recordPath(int $zone, string $label, string $type): string
    {
        return 'zones/'.$zone.'/rrsets/'.rawurlencode($label).'/'.$type;
    }

    /** @return array<string, mixed>|null */
    private function record(int $zone, string $label, string $type): ?array
    {
        $response = $this->request('GET', $this->recordPath($zone, $label, $type), allowMissing: true);
        if ($response->status() === 404) {
            return null;
        }
        $record = $response->json('rrset');
        if (! is_array($record) || ($record['name'] ?? null) !== $label || ($record['type'] ?? null) !== $type || ! is_array($record['records'] ?? null)) {
            throw ValidationException::withMessages(['dns' => 'Hetzner hat keinen gültigen DNS-Eintrag geliefert.']);
        }

        return $record;
    }

    /** @param array<string, mixed> $data */
    private function request(string $method, string $path, array $data = [], bool $allowMissing = false): Response
    {
        if (blank($this->settings->dnsToken())) {
            throw ValidationException::withMessages(['dns' => 'Kein DNS-Token hinterlegt. Bitte einen Token aus dem Projekt KonsoleH speichern.']);
        }
        try {
            $response = Http::withToken($this->settings->dnsToken())->acceptJson()->asJson()->withoutRedirecting()
                ->connectTimeout(5)->timeout(20)->send($method, 'https://api.hetzner.cloud/v1/'.$path,
                    $data === [] ? [] : ['json' => $data]);
        } catch (ConnectionException) {
            throw new PublicEndpointPendingException('Die DNS-API ist momentan nicht erreichbar.');
        }
        if ($response->status() === 429 || $response->serverError() || $response->status() === 409) {
            throw new PublicEndpointPendingException('Die DNS-API ist vorübergehend beschäftigt.');
        }
        if (! $response->successful() && ! ($allowMissing && $response->status() === 404)) {
            throw ValidationException::withMessages(['dns' => 'DNS-Anfrage nicht bestätigt (HTTP '.$response->status().'). Bitte Token, DNS-Projekt und Zone prüfen.']);
        }

        return $response;
    }
}
