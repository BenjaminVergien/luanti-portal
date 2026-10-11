<?php

namespace App\Services;

use App\Models\LuantiServer;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use SensitiveParameter;

class LuantiApiConnectionChecker
{
    public function __construct(private PortalAccess $access) {}

    /**
     * @param  array{api_base_url?: mixed, api_token?: mixed}  $settings
     * @return array{successful: bool, title: string, body: string}
     */
    public function check(User $actor, #[SensitiveParameter] array $settings = [], ?LuantiServer $server = null): array
    {
        Gate::forUser($this->access->currentUser($actor))->authorize(
            $server === null ? 'create' : 'update', $server ?? LuantiServer::class,
        );

        if ($server !== null) {
            $server = LuantiServer::query()->findOrFail($server->id);
        }

        $baseUrl = array_key_exists('api_base_url', $settings) ? $settings['api_base_url'] : $server?->api_base_url;
        $token = $settings['api_token'] ?? null;
        if (blank($token)) {
            $token = $server?->api_token;
        }
        if (is_string($baseUrl)) {
            $baseUrl = trim($baseUrl);
        }

        if (blank($baseUrl) || blank($token)) {
            return $this->result('API-Konfiguration fehlt', 'Für die Prüfung sind eine HTTPS-API-URL und ein API-Token erforderlich.');
        }

        $validator = Validator::make(['api_base_url' => $baseUrl, 'api_token' => $token], [
            'api_base_url' => ['required', 'string', 'max:2048', 'url:https'],
            'api_token' => ['required', 'string', 'not_regex:/[\r\n]/'],
        ]);
        $urlParts = is_string($baseUrl) ? parse_url($baseUrl) : false;
        if ($validator->fails() || $urlParts === false || array_intersect(['user', 'pass', 'query', 'fragment'], array_keys($urlParts)) !== []) {
            return $this->result('API-Konfiguration ungültig', 'Eine gültige HTTPS-Basis-URL ohne Zugangsdaten, Abfrageparameter oder Fragment und ein gültiger API-Token sind erforderlich.');
        }

        try {
            $response = Http::withToken($token)->acceptJson()->connectTimeout(3)->timeout(8)
                ->withoutRedirecting()->get(rtrim($baseUrl, '/').'/v1/lifecycle/jobs/connection-check-'.Str::uuid());
        } catch (ConnectionException) {
            return $this->result('API nicht erreichbar', 'Die Verbindung ist fehlgeschlagen. Bitte URL, Netzwerk und TLS-Zertifikat prüfen.');
        }

        if ($response->status() === 401) {
            return $this->result('API-Token abgelehnt', 'Die API akzeptiert den angegebenen Token nicht.');
        }
        if ($response->serverError()) {
            return $this->result('API-Fehler', 'Die API meldet einen Serverfehler. Bitte später erneut prüfen.');
        }
        if ($response->status() === 404 && $response->json('version') === 1 && $response->json('error.code') === 'unknown_job') {
            return $this->result('API-Verbindung erfolgreich', 'Die Bridge-API ist erreichbar und akzeptiert den API-Token.', true);
        }

        return $this->result('Unerwartete API-Antwort', 'Die Antwort entspricht nicht der Bridge-API. Bitte die HTTPS-Basis-URL prüfen.');
    }

    /** @return array{successful: bool, title: string, body: string} */
    private function result(string $title, string $body, bool $successful = false): array
    {
        return ['successful' => $successful, 'title' => $title, 'body' => $body];
    }
}
