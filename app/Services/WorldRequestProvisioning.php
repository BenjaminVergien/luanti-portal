<?php

namespace App\Services;

use App\Enums\WorldRequestStatus;
use App\Jobs\ProvisionWorldRequest;
use App\Jobs\ReconcileLuantiGame;
use App\Models\LuantiServer;
use App\Models\User;
use App\Models\WorldRequest;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Bus\UniqueLock;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

class WorldRequestProvisioning
{
    /** @param array<string, mixed> $data
     * @return array{name: string, server_type: string, location: string, image: string}
     */
    public function configuration(User $actor, array $data): array
    {
        Gate::forUser($actor)->authorize('create', LuantiServer::class);
        app(HetznerCloudClient::class)->assertConfigured();
        $settings = app(PortalSettings::class);
        if (! $settings->bridge()['bridge_enabled'] || $settings->luantiDockerImage() === null) {
            throw ValidationException::withMessages(['provisioning' => 'Bitte zuerst Luanti-Image und automatische Bridge-Installation in den Portaleinstellungen einrichten.']);
        }

        return Validator::make([
            'name' => $data['name'] ?? HetznerVirtualMachines::generatedName(),
            'server_type' => $data['server_type'] ?? 'cx23', 'location' => $data['location'] ?? 'nbg1', 'image' => 'ubuntu-24.04',
        ], [
            'name' => HetznerVirtualMachines::nameRules(),
            'server_type' => ['required', 'string', 'max:63', 'regex:/\A[a-z0-9-]+\z/'],
            'location' => ['required', 'string', 'max:63', 'regex:/\A[a-z0-9-]+\z/'],
            'image' => ['required', 'in:ubuntu-24.04'],
        ])->validate();
    }

    public function process(int $requestId): void
    {
        Cache::lock('luanti:request-provisioning:'.$requestId, 1260)->get(function () use ($requestId): void {
            $request = WorldRequest::query()->find($requestId);
            if ($request === null || $request->status !== WorldRequestStatus::Approved || $request->approved_access !== null
                || $request->provisioning_configuration === null || $request->provisioning_error !== null) {
                return;
            }
            $starts = $request->provisioning_starts_at
                ?? CarbonImmutable::parse($request->start_date->toDateString(), 'Europe/Berlin')->startOfDay();
            if ($starts->isFuture()) {
                return;
            }
            try {
                $actor = User::query()->find($request->decided_by);
                if ($actor === null || ! Gate::forUser($actor)->allows('create', LuantiServer::class)) {
                    throw ValidationException::withMessages(['provisioning' => 'Die genehmigende Person ist nicht mehr zur Serverbereitstellung berechtigt. Bitte durch einen Administrator erneut prüfen lassen.']);
                }
                $server = $request->provisionedServer;
                if ($server === null) {
                    if (CarbonImmutable::parse($request->end_date->toDateString(), 'Europe/Berlin')->addDay()->startOfDay()->lessThanOrEqualTo(now())) {
                        throw ValidationException::withMessages(['provisioning' => 'Der genehmigte Zeitraum ist bereits abgelaufen. Es wird keine neue VM erstellt.']);
                    }
                    $server = app(HetznerVirtualMachines::class)->create($actor, $request->provisioning_configuration, $request);
                }
                if ($server->trashed() || in_array($server->hetzner_status, ['deleted', 'deleting', 'create_failed', 'key_pending'], true)) {
                    throw ValidationException::withMessages(['provisioning' => $server->hetzner_error
                        ?? 'Die zugeordnete VM ist nicht bereit oder wurde gelöscht. Bitte den bestehenden Servereintrag prüfen. Es wird keine weitere VM erstellt.']);
                }
                if ($server->bridge_status === 'failed') {
                    throw ValidationException::withMessages(['provisioning' => $server->bridge_error ?? 'Bitte die Bridge-Installation auf dem zugeordneten Server erneut starten.']);
                }
                if ($server->hetzner_status === 'unknown') {
                    app(HetznerVirtualMachines::class)->refresh($actor, $server);

                    return;
                }
                if ($server->bridge_status !== 'installed' || $server->luanti_installation_status !== 'verified') {
                    return;
                }
                DB::transaction(function () use ($requestId, $server): void {
                    $server = LuantiServer::query()->whereKey($server->id)->lockForUpdate()->firstOrFail();
                    $request = WorldRequest::query()->whereKey($requestId)->lockForUpdate()->firstOrFail();
                    if ($request->status !== WorldRequestStatus::Approved || $request->approved_access !== null) {
                        return;
                    }
                    if ($server->hetzner_status !== 'running' || $server->bridge_status !== 'installed') {
                        return;
                    }
                    $snapshot = $request->template_snapshot;
                    $template = $server->templates()->where('template_identifier', $snapshot['template_id'])
                        ->where('revision', $snapshot['revision'])->where('bundle_sha256', $snapshot['bundle_sha256'])
                        ->where('is_available', true)->where('is_selectable', true)->first();
                    if ($template === null) {
                        throw ValidationException::withMessages(['provisioning' => 'Die angeforderte Vorlagenversion ist auf der VM noch nicht bestätigt. Bitte die Bridge-Installation prüfen.']);
                    }
                    $request->forceFill(['luanti_world_template_id' => $template->id])->unsetRelation('world');
                    $instance = app(LuantiGameInstances::class)->prepare($request);
                    $request->forceFill(['current_game_instance_id' => $instance->id]);
                    $request->forceFill(['approved_access' => app(LuantiAccounts::class)->snapshot($request),
                        'provisioning_error' => null, 'revision' => $request->revision + 1])->save();
                    app(WorldRequestMailings::class)->ensureFacilitatorAccount($request);
                    ReconcileLuantiGame::dispatch($request->id)->afterCommit();
                });
            } catch (ValidationException $exception) {
                $request->forceFill(['provisioning_error' => collect($exception->errors())->flatten()->first()])->save();
            } catch (ConnectionException|AuthorizationException) {
                $request->forceFill(['provisioning_error' => 'Die Serverbereitstellung konnte nicht bestätigt werden. Bitte API-Zugang und den vorhandenen Servereintrag prüfen und erneut versuchen.'])->save();
            } catch (Throwable $exception) {
                Log::warning('World request provisioning not confirmed', ['request_id' => $requestId, 'exception_type' => $exception::class]);
                $request->forceFill(['provisioning_error' => 'Die Serverbereitstellung konnte nicht bestätigt werden. Bitte den vorhandenen Servereintrag prüfen und die Bereitstellung erneut anfragen.'])->save();
            }
        });
    }

    public function retry(User $actor, WorldRequest $request): void
    {
        Gate::forUser($actor)->authorize('create', LuantiServer::class);
        $started = Cache::lock('luanti:request-provisioning:'.$request->id, 1260)->get(function () use ($actor, $request): bool {
            $request = $request->fresh();
            Gate::forUser($actor)->authorize('view', $request);
            if ($request->status !== WorldRequestStatus::Approved || $request->approved_access !== null || $request->provisioning_configuration === null) {
                throw ValidationException::withMessages(['provisioning' => 'Für diesen Antrag ist keine Serverbereitstellung offen.']);
            }
            $server = $request->provisionedServer;
            if ($server !== null && ! $server->trashed() && ! $request->provisioning_starts_at?->isFuture()) {
                if ($server->hetzner_status === 'key_pending') {
                    app(HetznerVirtualMachines::class)->retryKeySetup($actor, $server);
                } elseif ($server->bridge_status === 'failed' && $server->hetzner_status === 'running') {
                    app(BridgeProvisioning::class)->retry($actor, $server);
                }
            }
            $request->forceFill(['decided_by' => $actor->id, 'provisioning_error' => null])->save();
            app(UniqueLock::class)->release(new ProvisionWorldRequest($request->id));
            if (! $request->provisioning_starts_at?->isFuture()) {
                ProvisionWorldRequest::dispatch($request->id)->afterCommit();
            }

            return true;
        });
        if ($started !== true) {
            throw ValidationException::withMessages(['provisioning' => 'Für diesen Antrag läuft bereits eine Serverbereitstellung. Bitte kurz warten und den Status erneut prüfen.']);
        }
    }
}
