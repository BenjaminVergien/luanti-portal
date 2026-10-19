<?php

namespace App\Services;

use App\Enums\WorldRequestStatus;
use App\Jobs\ProcessLuantiAccounts;
use App\Models\LuantiAccountJob;
use App\Models\LuantiGameInstance;
use App\Models\LuantiLifecycleJob;
use App\Models\WorldRequest;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LuantiGameInstances
{
    public function __construct(private BridgeHttpClient $http) {}

    /** The caller holds the request lock. Never allocate a second copy on retry. */
    public function prepare(WorldRequest $request): LuantiGameInstance
    {
        $template = $request->world;
        if (! $template->is_available || ! $template->is_selectable || ! preg_match('/\A[0-9a-f]{64}\z/', $template->revision ?? '')) {
            throw ValidationException::withMessages(['luanti_world_template_id' => 'Bitte eine veröffentlichte, verfügbare Weltvorlage wählen.']);
        }
        $existing = $request->gameInstances()->where('is_archived', false)->where('luanti_world_template_id', $template->id)->latest('id')->first();
        if ($existing !== null) {
            return $existing;
        }

        return $request->gameInstances()->create([
            'luanti_world_template_id' => $template->id, 'luanti_server_id' => $template->luanti_server_id,
            'instance_identifier' => 'g'.strtolower((string) Str::ulid()), 'template_identifier' => $template->template_identifier,
            'template_revision' => $template->revision,
        ]);
    }

    public function reconcile(int $requestId): void
    {
        $request = WorldRequest::query()->findOrFail($requestId);
        $serverId = $request->approved_access['server_id'] ?? null;
        if ($serverId === null || $request->status === WorldRequestStatus::Terminated) {
            return;
        }
        Cache::lock('luanti:server:'.$serverId, 120)->get(function () use ($requestId): void {
            Cache::lock('luanti:accounts:process:'.$requestId, 120)->get(function () use ($requestId): void {
                $request = WorldRequest::query()->with('world', 'currentGameInstance')->findOrFail($requestId);
                if ($request->status === WorldRequestStatus::Terminated) {
                    return;
                }
                $window = ['starts_at' => $request->approved_access['starts_at'], 'ends_at' => $request->approved_access['ends_at']];
                $target = $request->gameInstances()->where('is_archived', false)->where('luanti_world_template_id', $request->luanti_world_template_id)->latest('id')->first();
                if ($target === null) {
                    return;
                }
                if (! $target->is_prepared) {
                    if ($this->perform($target, 'create_instance', ['template_id' => $target->template_identifier,
                        'template_revision' => $target->template_revision, 'access_window' => $window]) === null) {
                        return;
                    }
                    $target->update(['is_prepared' => true, 'state' => 'stopped', 'error' => null]);
                }
                if ($request->current_game_instance_id !== $target->id) {
                    $previous = $request->currentGameInstance;
                    if ($previous !== null && ! $previous->is_archived) {
                        if ($this->perform($previous, 'archive_instance') === null) {
                            return;
                        }
                    }
                    DB::transaction(function () use ($request, $previous, $target): void {
                        $locked = WorldRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
                        $previous?->update(['is_archived' => true, 'state' => 'archived', 'error' => null]);
                        if ($previous !== null) {
                            LuantiAccountJob::query()->where('luanti_game_instance_id', $previous->id)
                                ->whereIn('state', ['queued', 'pending', 'unknown'])->update(['state' => 'cancelled']);
                        }
                        $access = $locked->approved_access;
                        $access['instance_id'] = $target->id;
                        $access['generation']++;
                        $locked->forceFill(['current_game_instance_id' => $target->id, 'approved_access' => $access])->save();
                    });
                    $request = $request->fresh();
                }
                $enabled = $request->status !== WorldRequestStatus::Rejected;
                if ($this->perform($target, 'configure_instance', ['access_window' => $window,
                    'generation' => $request->approved_access['generation'], 'enabled' => $enabled]) === null) {
                    return;
                }
                $active = $enabled && now()->timestamp >= $window['starts_at'] && now()->timestamp < $window['ends_at'];
                $result = $this->perform($target, $active ? 'start_instance' : 'stop_instance', [], false);
                if ($result !== null) {
                    $target->update(['state' => $result['state'], 'error' => null]);
                    if ($active && $result['state'] === 'running') {
                        ProcessLuantiAccounts::dispatch($request->id);
                        app(WorldRequestMailings::class)->queueReadyIfEnterable($request->id);
                    }
                }
            });
        });
    }

    /** @param array<string, mixed> $extra
     * @return array<string, mixed>|null
     */
    private function perform(LuantiGameInstance $instance, string $operation, array $extra = [], bool $reuse = true): ?array
    {
        if ($instance->lifecycleJobs()->whereIn('state', ['failed', 'unknown'])->exists()) {
            return null;
        }
        $fields = ['version' => 2, 'operation' => $operation, 'instance_id' => $instance->instance_identifier, ...$extra];
        $job = $instance->lifecycleJobs()->whereIn('state', ['queued', 'pending'])->oldest('id')->first();
        if ($job === null && $reuse) {
            $job = $instance->lifecycleJobs()->where('operation', $operation)->where('state', 'completed')->latest('id')->get()->first(function (LuantiLifecycleJob $job) use ($fields): bool {
                $payload = json_decode($job->request_payload, true);
                unset($payload['job_id']);

                return $payload === $fields;
            });
            if ($job?->state === 'completed') {
                return $job->result;
            }
        }
        if ($job === null) {
            $jobId = 'game-'.Str::uuid();
            $job = $instance->lifecycleJobs()->create(['job_id' => $jobId, 'operation' => $operation, 'state' => 'queued',
                'base_url' => rtrim((string) $instance->server->api_base_url, '/'),
                'request_payload' => json_encode([...$fields, 'job_id' => $jobId], JSON_THROW_ON_ERROR)]);
        }
        $server = $instance->server;
        if ($job->operation === 'start_instance') {
            $instance->update(['state' => 'starting']);
        }
        $parts = parse_url($job->base_url);
        if ($parts === false || ($parts['scheme'] ?? null) !== 'https' || ! isset($parts['host'])
            || array_intersect(['user', 'pass', 'query', 'fragment'], array_keys($parts)) !== []
            || rtrim((string) $server->api_base_url, '/') !== $job->base_url || ! is_string($server->api_token)
            || $server->api_token === '' || preg_match('/[\r\n]/', $server->api_token)) {
            $instance->update(['state' => 'failed', 'error' => 'HTTPS-API-Konfiguration fehlt oder wurde geändert.']);

            return null;
        }
        try {
            $url = $job->base_url.'/v1/lifecycle/jobs';
            $response = $job->state === 'queued'
                ? $this->http->send('POST', $url, $server->api_token, 10, $job->request_payload)
                : $this->http->send('GET', $url.'/'.$job->job_id, $server->api_token, 10);
        } catch (ConnectionException) {
            $instance->update(['error' => 'Bridge nicht erreichbar. Derselbe Auftrag wird erneut geprüft.']);

            return null;
        } catch (BridgeResponseTooLargeException) {
            $job->update(['state' => 'unknown', 'error' => 'Bridge-Antwort zu groß; Ausgang unklar.']);
            $instance->update(['state' => 'failed', 'error' => $job->error]);

            return null;
        }
        $envelope = $response->json();
        if (! in_array($response->status(), [200, 202], true) || ! is_array($envelope)
            || ($envelope['version'] ?? null) !== 1 || ($envelope['job_id'] ?? null) !== $job->job_id) {
            if (in_array($response->status(), [400, 404, 409, 413, 415], true)) {
                $job->update(['state' => $response->status() === 409 ? 'unknown' : 'failed', 'error' => 'Die Bridge hat den Spielauftrag abgelehnt.']);
            }
            $instance->update(['state' => 'failed', 'error' => 'Ungültige Bridge-Antwort; bestehender Auftrag bleibt erhalten.']);

            return null;
        }
        if (($envelope['state'] ?? null) === 'pending') {
            $job->update(['state' => 'pending']);

            return null;
        }
        $result = $envelope['result'] ?? null;
        if (($envelope['state'] ?? null) !== 'completed' || ! is_array($result) || ($result['version'] ?? null) !== 2
            || ($result['job_id'] ?? null) !== $job->job_id || ($result['instance_id'] ?? null) !== $instance->instance_identifier
            || ! is_bool($result['ok'] ?? null)) {
            $job->update(['state' => 'unknown', 'error' => 'Ausgang unbekannt. Bitte den bestehenden Auftrag prüfen.']);
            $instance->update(['state' => 'failed', 'error' => $job->error]);

            return null;
        }
        $result = array_intersect_key($result, array_flip(['version', 'job_id', 'instance_id', 'ok', 'state', 'error']));
        if (isset($result['error'])) {
            $code = $result['error']['code'] ?? null;
            $result['error'] = ['code' => is_string($code) && preg_match('/\A[a-z_]{1,64}\z/', $code) ? $code : 'instance_failed'];
        }
        if (! $result['ok'] || ! in_array($result['state'] ?? null, ['stopped', 'starting', 'running', 'archived'], true)) {
            if (in_array($result['error']['code'] ?? null, ['busy', 'schedule_conflict', 'already_running', 'outside_approval'], true)) {
                $job->update(['state' => 'superseded', 'result' => $result, 'error' => 'Die Spielinstanz wartet auf den reservierten Server.']);
                $instance->update(['error' => $job->error]);

                return null;
            }
            $job->update(['state' => 'failed', 'result' => $result, 'error' => 'Spielinstanz konnte nicht verarbeitet werden.']);
            $instance->update(['state' => 'failed', 'error' => $job->error]);

            return null;
        }
        $job->update(['state' => 'completed', 'result' => $result, 'error' => null]);
        $instance->update(['state' => $result['state'], 'error' => null]);

        return $job->operation === $operation ? $result : null;
    }

    public function retry(WorldRequest $request): void
    {
        foreach ($request->gameInstances as $instance) {
            foreach ($instance->lifecycleJobs()->whereIn('state', ['failed', 'unknown'])->get() as $job) {
                if ($job->state === 'unknown') {
                    $job->update(['state' => 'pending']);
                } else {
                    $payload = json_decode($job->request_payload, true, flags: JSON_THROW_ON_ERROR);
                    $jobId = 'game-'.Str::uuid();
                    $payload['job_id'] = $jobId;
                    $job->update(['state' => 'superseded']);
                    $instance->lifecycleJobs()->create(['job_id' => $jobId, 'operation' => $job->operation, 'state' => 'queued',
                        'base_url' => $job->base_url, 'request_payload' => json_encode($payload, JSON_THROW_ON_ERROR)]);
                }
            }
        }
    }
}
