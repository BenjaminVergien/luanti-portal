<?php

namespace App\Services;

use App\Enums\WorldRequestStatus;
use App\Jobs\ProcessLuantiAccounts;
use App\Models\LuantiAccount;
use App\Models\LuantiAccountAssignment;
use App\Models\LuantiAccountJob;
use App\Models\LuantiGameInstance;
use App\Models\User;
use App\Models\WorldRequest;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class LuantiAccounts
{
    public function __construct(private BridgeHttpClient $http) {}

    public function authorize(User $actor, WorldRequest $request): void
    {
        Gate::forUser($actor)->authorize('manageAccounts', $request);
    }

    /** @return array{instance_id: int|null, server_id: int, starts_at: int, ends_at: int, generation: int} */
    public function snapshot(WorldRequest $request): array
    {
        return ['instance_id' => $request->current_game_instance_id, 'server_id' => $request->world->luanti_server_id,
            'starts_at' => CarbonImmutable::parse($request->start_date->toDateString(), 'Europe/Berlin')->startOfDay()->timestamp,
            'ends_at' => CarbonImmutable::parse($request->end_date->toDateString(), 'Europe/Berlin')->addDay()->startOfDay()->timestamp,
            'generation' => ($request->approved_access['generation'] ?? 0) + 1];
    }

    /** @param list<array{username: mixed, password: mixed, role: mixed}> $rows */
    public function create(User $actor, WorldRequest $request, #[\SensitiveParameter] array $rows): void
    {
        $this->authorize($actor, $request);
        $data = Validator::make(['accounts' => $rows], [
            'accounts' => ['required', 'array', 'min:1', 'max:100'],
            'accounts.*.username' => ['required', 'string', 'regex:/\\A[A-Za-z0-9_-]{1,20}\\z/', 'distinct:ignore_case'],
            'accounts.*.password' => ['required', 'string', 'min:12', 'max:1024'],
            'accounts.*.role' => ['required', Rule::in(['participant', 'facilitator'])],
        ])->validate();
        DB::transaction(function () use ($actor, $request, $data): void {
            $request = WorldRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
            $this->authorize($actor, $request);
            if ($request->status !== WorldRequestStatus::Approved) {
                throw ValidationException::withMessages(['accounts' => 'Neue Spielaccounts benötigen einen genehmigten Weltantrag.']);
            }
            if ($request->world->luanti_server_id === null) {
                throw ValidationException::withMessages(['accounts' => 'Der Server und die private Spielinstanz werden noch bereitgestellt.']);
            }
            $snapshot = $request->approved_access ?? $this->snapshot($request);
            if ($snapshot['instance_id'] === null) {
                throw ValidationException::withMessages(['accounts' => 'Die private Spielinstanz wird noch vorbereitet.']);
            }
            $world = LuantiGameInstance::query()->whereKey($snapshot['instance_id'])->lockForUpdate()->firstOrFail();
            if ($world->is_archived) {
                throw ValidationException::withMessages(['accounts' => 'Die genehmigte Welt ist nicht mehr verfügbar.']);
            }
            $request->forceFill(['approved_access' => $snapshot])->save();
            foreach ($data['accounts'] as $index => $row) {
                $normalized = strtolower($row['username']);
                if (LuantiAccount::query()->where('world_request_id', $request->id)->where('normalized_username', $normalized)->exists()
                    || LuantiAccountAssignment::query()->where('luanti_game_instance_id', $world->id)->where('normalized_username', $normalized)->exists()) {
                    throw ValidationException::withMessages(['accounts.'.$index.'.username' => 'Dieser Spielname ist für den Antrag oder diese Welt bereits vergeben.']);
                }
                $account = LuantiAccount::query()->create(['world_request_id' => $request->id, 'username' => $row['username'],
                    'normalized_username' => $normalized, 'role' => $row['role'], 'initial_password' => $row['password']]);
                $this->reserve($account, $world->id);
            }
            ProcessLuantiAccounts::dispatch($request->id)->afterCommit();
        });
    }

    public function revealPassword(User $actor, LuantiAccount $account): string
    {
        $account = $account->fresh();
        $this->authorize($actor, $account->worldRequest);

        return $account->initial_password;
    }

    public function retry(User $actor, WorldRequest $request): void
    {
        $this->authorize($actor, $request);
        app(LuantiGameInstances::class)->retry($request);
        DB::transaction(function () use ($actor, $request): void {
            $request = WorldRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
            $this->authorize($actor, $request);
            $unknown = LuantiAccountJob::query()->where('world_request_id', $request->id)->where('luanti_game_instance_id', $request->current_game_instance_id)->where('state', 'unknown')->first();
            if ($unknown !== null) {
                $unknown->update(['state' => 'pending']);
            } else {
                LuantiAccountAssignment::query()->where('luanti_game_instance_id', $request->current_game_instance_id)
                    ->whereHas('account', fn (Builder $query): Builder => $query->where('world_request_id', $request->id))->update(['error' => null]);
            }
            ProcessLuantiAccounts::dispatch($request->id)->afterCommit();
        });
    }

    public function canCorrect(LuantiAccount $account): bool
    {
        $instanceId = $account->worldRequest->current_game_instance_id;
        $assignments = $account->assignments()->where('luanti_game_instance_id', $instanceId);

        return ! LuantiAccountJob::query()->where('luanti_game_instance_id', $instanceId)->whereIn('state', ['queued', 'pending', 'unknown'])->exists()
            && (clone $assignments)->exists()
            && ! (clone $assignments)->whereNotNull('applied_window')->exists()
            && ! (clone $assignments)->where('outcome_uncertain', true)->exists()
            && ! (clone $assignments)->where(fn (Builder $query): Builder => $query->whereNull('error')
                ->orWhereNotIn('error', ['protected_account', 'existing_account']))->exists();
    }

    /** @param array{username: mixed, password: mixed} $data */
    public function correctRejected(User $actor, LuantiAccount $account, #[\SensitiveParameter] array $data): void
    {
        $this->authorize($actor, $account->fresh()->worldRequest);
        $validated = Validator::make($data, ['username' => ['required', 'string', 'regex:/\A[A-Za-z0-9_-]{1,20}\z/'],
            'password' => ['required', 'string', 'min:12', 'max:1024']])->validate();
        DB::transaction(function () use ($actor, $account, $validated): void {
            $request = WorldRequest::query()->whereKey($account->world_request_id)->lockForUpdate()->firstOrFail();
            $this->authorize($actor, $request);
            $account = $account->fresh();
            if ($request->status !== WorldRequestStatus::Approved || ! $this->canCorrect($account)) {
                throw ValidationException::withMessages(['username' => 'Dieser Account kann nicht sicher umbenannt werden. Bitte den bestehenden Auftrag prüfen.']);
            }
            $normalized = strtolower($validated['username']);
            if (LuantiAccount::query()->where('world_request_id', $request->id)->where('normalized_username', $normalized)->whereKeyNot($account->id)->exists()) {
                throw ValidationException::withMessages(['username' => 'Dieser Spielname ist bereits vergeben.']);
            }
            $assignments = $account->assignments()->where('luanti_game_instance_id', $request->current_game_instance_id)->orderBy('luanti_game_instance_id')->get();
            LuantiGameInstance::query()->whereIn('id', $assignments->pluck('luanti_game_instance_id'))->orderBy('id')->lockForUpdate()->get();
            foreach ($assignments as $assignment) {
                if (LuantiAccountAssignment::query()->where('luanti_game_instance_id', $assignment->luanti_game_instance_id)->where('normalized_username', $normalized)
                    ->where('luanti_account_id', '<>', $account->id)->exists()) {
                    throw ValidationException::withMessages(['username' => 'Dieser Spielname ist in der Welt bereits vergeben.']);
                }
                $assignment->update(['normalized_username' => $normalized, 'error' => null]);
            }
            $account->update(['username' => $validated['username'], 'normalized_username' => $normalized, 'initial_password' => $validated['password']]);
            ProcessLuantiAccounts::dispatch($request->id)->afterCommit();
        });
    }

    public function status(LuantiAccount $account): string
    {
        $assignments = $account->assignments->where('luanti_game_instance_id', $account->worldRequest->current_game_instance_id);
        if ($assignments->contains(fn (LuantiAccountAssignment $assignment): bool => $assignment->error !== null)) {
            return 'Fehlgeschlagen';
        }
        if ($assignments->contains(fn (LuantiAccountAssignment $assignment): bool => $assignment->outcome_uncertain)) {
            return 'Ausgang unklar';
        }
        $assignment = $assignments->first(fn (LuantiAccountAssignment $assignment): bool => $assignment->applied_admitted && $assignment->applied_window !== null);
        if ($assignment === null) {
            return 'Vorbereitet';
        }
        $now = now()->timestamp;

        return $now < $assignment->applied_window['starts_at'] ? 'Noch nicht freigegeben'
            : ($now >= $assignment->applied_window['ends_at'] ? 'Abgelaufen' : 'Freigegeben');
    }

    public function reconcile(int $requestId): void
    {
        DB::transaction(function () use ($requestId): void {
            $request = WorldRequest::query()->whereKey($requestId)->lockForUpdate()->firstOrFail();
            $desired = $request->approved_access;
            if ($desired === null && $request->status === WorldRequestStatus::Approved && $request->world->luanti_server_id !== null) {
                $desired = $this->snapshot($request);
                $request->forceFill(['approved_access' => $desired])->save();
            }
            $this->cancelSupersededUnsentJobs($request);
            if ($request->status === WorldRequestStatus::Rejected) {
                return;
            }
            if (LuantiAccountJob::query()->where('world_request_id', $requestId)->whereIn('state', ['queued', 'pending', 'unknown'])->exists()) {
                return;
            }
            if ($desired === null) {
                return;
            }
            $window = ['starts_at' => $desired['starts_at'], 'ends_at' => $desired['ends_at']];
            $accounts = LuantiAccount::query()->where('world_request_id', $requestId)->with('assignments')->orderBy('id')->get();
            $assignments = $accounts->flatMap->assignments->where('luanti_game_instance_id', $desired['instance_id']);
            $old = $assignments->filter(fn (LuantiAccountAssignment $assignment): bool => $assignment->error === null && $assignment->applied_admitted
                && ($assignment->luanti_game_instance_id !== $desired['instance_id'] || $assignment->applied_window !== $window));
            if ($old->isNotEmpty()) {
                $worldId = $old->first()->luanti_game_instance_id;
                $batch = $old->where('luanti_game_instance_id', $worldId)->take(100);
                $this->makeJob($request, $worldId, 'revoke', $desired['generation'], $batch->map(fn (LuantiAccountAssignment $assignment): array => [
                    'account' => $accounts->firstWhere('id', $assignment->luanti_account_id), 'window' => $assignment->applied_window,
                ])->values()->all());

                return;
            }
            if ($assignments->contains(fn (LuantiAccountAssignment $assignment): bool => $assignment->error !== null)) {
                return;
            }
            $world = LuantiGameInstance::query()->whereKey($desired['instance_id'])->lockForUpdate()->firstOrFail();
            if ($world->is_archived || ! $world->is_prepared || $world->state !== 'running') {
                return;
            }
            $batch = [];
            foreach ($accounts as $account) {
                $assignment = $account->assignments->firstWhere('luanti_game_instance_id', $world->id);
                if ($assignment?->applied_admitted && $assignment->applied_window === $window && ! $assignment->outcome_uncertain) {
                    continue;
                }
                if (! $this->reserve($account, $world->id)) {
                    return;
                }
                $batch[] = ['account' => $account, 'window' => $window];
                if (count($batch) === 100) {
                    break;
                }
            }
            if ($batch !== []) {
                $this->makeJob($request, $world->id, 'grant', $desired['generation'], $batch);
            }
        });
    }

    /** The caller must hold the world request's database row lock. */
    public function cancelSupersededUnsentJobs(WorldRequest $request): void
    {
        $generation = $request->approved_access['generation'] ?? null;
        LuantiAccountJob::query()->where('world_request_id', $request->id)->where('state', 'queued')->whereNull('submitted_at')
            ->when($generation !== null, fn (Builder $query): Builder => $query->where('generation', '<>', $generation))
            ->update(['state' => 'cancelled', 'error' => null]);
    }

    private function reserve(LuantiAccount $account, int $worldId): bool
    {
        $assignment = LuantiAccountAssignment::query()->firstOrCreate([
            'luanti_game_instance_id' => $worldId, 'normalized_username' => $account->normalized_username,
        ], ['luanti_account_id' => $account->id]);
        if ($assignment->luanti_account_id !== $account->id) {
            $account->assignments()->where('luanti_game_instance_id', '<>', $worldId)->update(['error' => 'Spielname in der Zielwelt bereits vergeben.']);

            return false;
        }

        return true;
    }

    /** @param list<array{account: LuantiAccount, window: array{starts_at: int, ends_at: int}}> $batch */
    private function makeJob(WorldRequest $request, int $worldId, string $operation, int $generation, #[\SensitiveParameter] array $batch): void
    {
        $world = LuantiGameInstance::query()->with('server')->findOrFail($worldId);
        $jobId = 'portal-'.Str::uuid();
        $entries = array_map(fn (array $entry): array => [
            'username' => $entry['account']->username, 'role' => $entry['account']->role, 'admitted' => $operation === 'grant',
            'access_window' => $entry['window'], ...(! LuantiAccountAssignment::query()
                ->where('luanti_account_id', $entry['account']->id)->where('luanti_game_instance_id', $worldId)->where('is_provisioned', true)->exists()
                    ? ['initial_password' => $entry['account']->initial_password] : []),
        ], $batch);
        $payload = json_encode(['version' => 2, 'job_id' => $jobId, 'operation' => 'sync_accounts', 'accounts' => $entries], JSON_THROW_ON_ERROR);
        while (strlen($payload) > 256 * 1024) {
            array_pop($entries);
            array_pop($batch);
            $payload = json_encode(['version' => 2, 'job_id' => $jobId, 'operation' => 'sync_accounts', 'accounts' => $entries], JSON_THROW_ON_ERROR);
        }
        LuantiAccountJob::query()->create(['world_request_id' => $request->id, 'luanti_game_instance_id' => $worldId,
            'job_id' => $jobId, 'operation' => $operation, 'state' => 'queued', 'generation' => $generation,
            'base_url' => rtrim((string) $world->server->api_base_url, '/'), 'instance_identifier' => $world->instance_identifier,
            'account_ids' => array_map(fn (array $entry): int => $entry['account']->id, $batch), 'request_payload' => $payload]);
    }

    public function process(int $jobId): void
    {
        $job = LuantiAccountJob::query()->findOrFail($jobId);
        Cache::lock('luanti:accounts:process:'.$job->world_request_id, 60)->get(function () use ($job): void {
            $job = DB::transaction(function () use ($job): ?LuantiAccountJob {
                $request = WorldRequest::query()->whereKey($job->world_request_id)->lockForUpdate()->firstOrFail();
                $this->cancelSupersededUnsentJobs($request);
                $job = $job->fresh();
                if (! in_array($job->state, ['queued', 'pending'], true)) {
                    return null;
                }
                if ($job->instance->is_archived || $job->luanti_game_instance_id !== $request->current_game_instance_id) {
                    $job->update(['state' => 'cancelled']);

                    return null;
                }
                $server = $job->instance->server;
                $base = rtrim((string) $server->api_base_url, '/');
                $parts = parse_url($base);
                if ($parts === false || ($parts['scheme'] ?? null) !== 'https' || ! isset($parts['host'])
                    || array_intersect(['user', 'pass', 'query', 'fragment'], array_keys($parts)) !== []
                    || $base !== $job->base_url || ! is_string($server->api_token) || $server->api_token === ''
                    || preg_match('/[\\r\\n]/', $server->api_token)) {
                    $job->update(['error' => 'HTTPS-API-Konfiguration fehlt oder wurde geändert.']);

                    return null;
                }
                if ($job->state === 'queued') {
                    if ($job->generation !== ($request->approved_access['generation'] ?? null)) {
                        $job->update(['state' => 'pending']);
                    } elseif ($job->submitted_at === null) {
                        $job->update(['submitted_at' => now()]);
                    }
                }

                return $job;
            });
            if ($job === null) {
                return;
            }
            $server = $job->instance->server;
            $url = $job->base_url.'/v1/worlds/'.rawurlencode($job->instance_identifier).'/accounts/jobs';
            try {
                $response = $job->state === 'queued'
                    ? $this->http->send('POST', $url, $server->api_token, 10, $job->request_payload)
                    : $this->http->send('GET', $url.'/'.rawurlencode($job->job_id), $server->api_token, 10);
            } catch (BridgeResponseTooLargeException) {
                $this->unknown($job);

                return;
            } catch (ConnectionException) {
                $job->update(['error' => 'Bridge nicht erreichbar. Der bestehende Auftrag wird erneut geprüft.']);

                return;
            }
            if (! in_array($response->status(), [200, 202], true)) {
                if (in_array($response->status(), [400, 404, 409, 413, 415], true)) {
                    if ($job->state === 'pending' || $response->status() === 409) {
                        $this->unknown($job);
                    } else {
                        $this->rejectJob($job, $response->status() === 400
                            ? 'Bridge-Vertrag abgelehnt. Bitte die Bridge auf Account-API Version 2 aktualisieren.'
                            : 'Die Bridge hat den Account-Auftrag abgelehnt.');
                    }
                } else {
                    $job->update(['error' => $response->status() === 401 ? 'API-Token wurde abgelehnt.' : 'Bridge meldet einen Fehler. Auftrag bleibt erhalten.']);
                }

                return;
            }
            $envelope = $response->json();
            if (! is_array($envelope) || ($envelope['version'] ?? null) !== 1 || ($envelope['job_id'] ?? null) !== $job->job_id
                || ($envelope['world_id'] ?? null) !== $job->instance_identifier) {
                $this->unknown($job);

                return;
            }
            if (($envelope['state'] ?? null) === 'pending') {
                $job->update(['state' => 'pending', 'error' => null]);
            } elseif (($envelope['state'] ?? null) === 'completed') {
                $this->complete($job, $envelope['result'] ?? null);
            } else {
                $this->unknown($job);
            }
        });
    }

    private function unknown(LuantiAccountJob $job): void
    {
        $job->update(['state' => 'unknown', 'error' => 'Ausgang unbekannt. Neue Freigaben warten auf Klärung.']);
    }

    private function rejectJob(LuantiAccountJob $job, string $message): void
    {
        DB::transaction(function () use ($job, $message): void {
            $job->update(['state' => 'completed', 'error' => $message]);
            LuantiAccountAssignment::query()->where('luanti_game_instance_id', $job->luanti_game_instance_id)
                ->whereIn('luanti_account_id', $job->account_ids)->update(['error' => $message]);
        });
    }

    private function complete(LuantiAccountJob $job, mixed $result): void
    {
        $payload = json_decode($job->request_payload, true, flags: JSON_THROW_ON_ERROR);
        if (is_array($result) && ($result['version'] ?? null) === 1 && ($result['code'] ?? null) === 'invalid_job') {
            $this->rejectJob($job, 'Die Luanti-Bridge muss für zeitlich begrenzte Accounts aktualisiert werden.');

            return;
        }
        if (! is_array($result) || ($result['version'] ?? null) !== 2 || ($result['job_id'] ?? null) !== $job->job_id
            || ! in_array($result['status'] ?? null, ['ok', 'partial', 'error'], true) || ! is_array($result['accounts'] ?? null)) {
            $this->unknown($job);

            return;
        }
        if ($result['accounts'] === [] && $result['status'] === 'error' && is_string($result['code'] ?? null)) {
            $this->rejectJob($job, 'Die Bridge hat den Account-Auftrag abgelehnt.');

            return;
        }
        if (count($result['accounts']) !== count($payload['accounts'])) {
            $this->unknown($job);

            return;
        }
        $safeResults = [];
        foreach ($payload['accounts'] as $index => $entry) {
            $outcome = $result['accounts'][$index] ?? null;
            if (! is_array($outcome) || ($outcome['username'] ?? null) !== $entry['username']
                || ! in_array($outcome['status'] ?? null, ['created', 'updated', 'unchanged', 'rejected'], true)) {
                $this->unknown($job);

                return;
            }
            $safeResults[] = ['username' => $entry['username'], 'status' => $outcome['status'],
                ...($outcome['status'] === 'rejected' ? ['code' => $this->safeCode($outcome['code'] ?? null)] : [])];
        }
        DB::transaction(function () use ($job, $payload, $safeResults, $result): void {
            $request = WorldRequest::query()->whereKey($job->world_request_id)->lockForUpdate()->firstOrFail();
            foreach ($safeResults as $index => $outcome) {
                $assignment = LuantiAccountAssignment::query()->where('luanti_game_instance_id', $job->luanti_game_instance_id)
                    ->where('luanti_account_id', $job->account_ids[$index])->firstOrFail();
                if ($outcome['status'] === 'rejected') {
                    $changes = ['error' => $outcome['code']];
                    if (! in_array($outcome['code'], ['protected_account', 'existing_account', 'initial_password_required'], true)) {
                        $changes['outcome_uncertain'] = true;
                        if ($job->operation === 'grant') {
                            $changes['applied_window'] = $payload['accounts'][$index]['access_window'];
                            $changes['applied_admitted'] = true;
                        }
                    }
                    $assignment->update($changes);
                } else {
                    $entry = $payload['accounts'][$index];
                    $assignment->update(['applied_window' => $entry['access_window'], 'applied_admitted' => $entry['admitted'],
                        'is_provisioned' => true, 'outcome_uncertain' => false, 'error' => null]);
                }
            }
            $job->update(['state' => 'completed', 'error' => null,
                'result' => ['version' => 2, 'job_id' => $job->job_id, 'status' => $result['status'], 'accounts' => $safeResults,
                    'superseded' => $job->generation !== ($request->approved_access['generation'] ?? null)]]);
        });
        app(WorldRequestMailings::class)->queueReadyIfEnterable($job->world_request_id);
    }

    private function safeCode(mixed $code): string
    {
        return in_array($code, ['protected_account', 'existing_account', 'initial_password_required', 'storage_error',
            'account_creation_failed', 'account_update_failed'], true) ? $code : 'account_rejected';
    }
}
