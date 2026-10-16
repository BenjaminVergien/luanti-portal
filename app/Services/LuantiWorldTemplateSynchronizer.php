<?php

namespace App\Services;

use App\Models\LuantiServer;
use App\Models\LuantiWorldTemplate;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use JsonException;
use stdClass;

class LuantiWorldTemplateSynchronizer
{
    public function __construct(private PortalAccess $access, private BridgeHttpClient $http) {}

    /** @return array{successful: bool, title: string, body: string} */
    public function sync(User $actor, LuantiServer $server): array
    {
        Gate::forUser($this->access->currentUser($actor))->authorize('update', $server);

        return $this->synchronize($server, $actor);
    }

    /** @return array<int, array{successful: bool, title: string, body: string}> */
    public function syncConfiguredServers(?int $serverId = null): array
    {
        $results = [];
        foreach (LuantiServer::query()->whereNotNull('api_base_url')->whereNotNull('api_token')
            ->when($serverId !== null, fn (Builder $query): Builder => $query->whereKey($serverId))->lazyById() as $server) {
            $results[$server->id] = $this->synchronize($server);
        }

        return $results;
    }

    /** @return array{successful: bool, title: string, body: string} */
    private function synchronize(LuantiServer $server, ?User $actor = null): array
    {
        $result = Cache::lock('luanti:worlds:sync:'.$server->id, 120)->get(function () use ($server, $actor): array {
            $server = LuantiServer::query()->findOrFail($server->id);
            $baseUrl = is_string($server->api_base_url) ? trim($server->api_base_url) : $server->api_base_url;
            $token = $server->api_token;
            if (! $this->validSettings($baseUrl, $token)) {
                return $this->failure($server, $actor, 'API-Konfiguration fehlt oder ist ungültig',
                    'Bitte eine gültige HTTPS-API-URL und einen API-Token hinterlegen.');
            }

            try {
                $response = $this->http->send('GET', rtrim($baseUrl, '/').'/v1/world-templates', $token, 8);
            } catch (BridgeResponseTooLargeException) {
                return $this->failure($server, $actor, 'Weltliste zu groß',
                    'Die Antwort der Bridge überschreitet die erlaubte Größe von 256 KiB. Die gespeicherte Weltliste bleibt erhalten.');
            } catch (ConnectionException) {
                return $this->failure($server, $actor, 'Bridge nicht erreichbar',
                    'Welten konnten nicht geladen werden. Bitte Netzwerk, API-URL und TLS-Zertifikat prüfen.');
            }
            if (! $response->ok()) {
                return $this->failure($server, $actor, 'Weltliste konnte nicht geladen werden',
                    match ($response->status()) {
                        401 => 'Die Bridge hat den API-Token abgelehnt.',
                        404 => 'Die Bridge bietet die Weltliste noch nicht an. Bitte die Bridge-API aktualisieren.',
                        default => 'Die Bridge meldet einen Fehler. Die gespeicherte Weltliste bleibt erhalten.',
                    });
            }
            $worlds = $this->parseCatalogue($response->body());
            if ($worlds === null) {
                return $this->failure($server, $actor, 'Ungültige Weltliste',
                    'Die Antwort entspricht nicht der Bridge-API. Die gespeicherte Weltliste bleibt erhalten.');
            }

            return DB::transaction(function () use ($server, $actor, $worlds): array {
                $current = LuantiServer::query()->whereKey($server->id)->lockForUpdate()->firstOrFail();
                $this->authorizeCurrentActor($actor, $current);
                if ($current->api_base_url !== $server->api_base_url || $current->api_token !== $server->api_token) {
                    return $this->result(false, 'API-Konfiguration wurde geändert', 'Bitte die Synchronisierung erneut starten.');
                }
                $seen = [];
                $created = 0;
                $now = now();
                foreach ($worlds as $entry) {
                    $world = LuantiWorldTemplate::query()->firstOrNew([
                        'luanti_server_id' => $current->id, 'template_identifier' => $entry['template_id'],
                    ]);
                    if (! $world->exists) {
                        $world->fill(['name' => $entry['name'], 'is_selectable' => true]);
                        $created++;
                    }
                    $world->forceFill(['is_available' => true, 'last_discovered_at' => $now, 'revision' => $entry['revision']])->save();
                    $seen[] = $world->id;
                }
                $missing = $current->templates()->whereNotNull('last_discovered_at')->where('is_available', true)
                    ->whereNotIn('id', $seen)->update(['is_available' => false, 'updated_at' => $now]);
                $current->forceFill(['worlds_synced_at' => $now, 'world_sync_error' => null])->save();

                return $this->result(true, 'Welten synchronisiert',
                    count($worlds).' Welten gefunden, '.$created.' neu übernommen, '.$missing.' nicht mehr vorhanden.');
            });
        });

        return $result === false ? $this->result(false, 'Synchronisierung läuft bereits', 'Bitte in wenigen Sekunden erneut versuchen.') : $result;
    }

    private function validSettings(mixed $baseUrl, mixed $token): bool
    {
        $validator = Validator::make(['api_base_url' => $baseUrl, 'api_token' => $token], [
            'api_base_url' => ['required', 'string', 'max:2048', 'url:https'],
            'api_token' => ['required', 'string', 'not_regex:/[\r\n]/'],
        ]);
        $parts = is_string($baseUrl) ? parse_url($baseUrl) : false;

        return ! $validator->fails() && $parts !== false
            && array_intersect(['user', 'pass', 'query', 'fragment'], array_keys($parts)) === [];
    }

    /** @return list<array{template_id: string, name: string, revision: string}>|null */
    private function parseCatalogue(string $body): ?array
    {
        try {
            $value = json_decode($body, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
        if (! $value instanceof stdClass || ($value->version ?? null) !== 1
            || ! isset($value->templates) || ! is_array($value->templates) || count($value->templates) > 1000) {
            return null;
        }
        $worlds = [];
        $seen = [];
        foreach ($value->templates as $entry) {
            if (! $entry instanceof stdClass || ! is_string($entry->template_id ?? null)
                || ! preg_match('/\\A[A-Za-z0-9_-]{1,32}\\z/', $entry->template_id)
                || ! is_string($entry->revision ?? null) || ! preg_match('/\A[0-9a-f]{64}\z/', $entry->revision)
                || ! is_string($entry->name ?? null) || trim($entry->name) === '' || mb_strlen($entry->name) > 255
                || isset($seen[$entry->template_id])) {
                return null;
            }
            $seen[$entry->template_id] = true;
            $worlds[] = ['template_id' => $entry->template_id, 'name' => trim($entry->name), 'revision' => $entry->revision];
        }

        return $worlds;
    }

    private function authorizeCurrentActor(?User $actor, LuantiServer $server): void
    {
        if ($actor !== null) {
            Gate::forUser($this->access->currentUser($actor))->authorize('update', $server);
        }
    }

    /** @return array{successful: bool, title: string, body: string} */
    private function failure(LuantiServer $server, ?User $actor, string $title, string $body): array
    {
        DB::transaction(function () use ($server, $actor, $body): void {
            $current = LuantiServer::query()->whereKey($server->id)->lockForUpdate()->firstOrFail();
            $this->authorizeCurrentActor($actor, $current);
            if ($current->api_base_url === $server->api_base_url && $current->api_token === $server->api_token) {
                $current->forceFill(['world_sync_error' => $body])->save();
            }
        });

        return $this->result(false, $title, $body);
    }

    /** @return array{successful: bool, title: string, body: string} */
    private function result(bool $successful, string $title, string $body): array
    {
        return ['successful' => $successful, 'title' => $title, 'body' => $body];
    }
}
