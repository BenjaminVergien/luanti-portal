<?php

namespace App\Services;

use App\Enums\WorldRequestStatus;
use App\Jobs\TerminateWorldRequest;
use App\Models\LuantiAccountJob;
use App\Models\User;
use App\Models\WorldRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class WorldRequestTermination
{
    public function confirmationName(WorldRequest $request): string
    {
        return $request->provisionedServer?->name ?? $request->provisioning_configuration['name']
            ?? $request->world->server?->name ?? $request->name;
    }

    public function terminate(User $actor, WorldRequest $request, string $confirmationName, int $expectedRevision): void
    {
        Gate::forUser(app(PortalAccess::class)->currentUser($actor))->authorize('terminate', $request);
        $saved = Cache::lock('luanti:request-provisioning:'.$request->id, 1260)->get(function () use ($actor, $request, $confirmationName, $expectedRevision): bool {
            return Cache::lock('luanti:accounts:process:'.$request->id, 120)->get(function () use ($actor, $request, $confirmationName, $expectedRevision): bool {
                DB::transaction(function () use ($actor, $request, $confirmationName, $expectedRevision): void {
                    $request = WorldRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
                    Gate::forUser(app(PortalAccess::class)->currentUser($actor))->authorize('terminate', $request);
                    if ($request->revision !== $expectedRevision) {
                        throw ValidationException::withMessages(['revision' => 'Der Antrag wurde inzwischen geändert. Bitte schließe das Formular und öffne es erneut.']);
                    }
                    Validator::make(['confirmation_name' => $confirmationName], [
                        'confirmation_name' => ['required', 'string', Rule::in([$this->confirmationName($request)])],
                    ], ['confirmation_name.in' => 'Der Name stimmt nicht überein. Bitte den Namen exakt eingeben.'])->validate();
                    if ($request->provisionedServer === null && $request->world->server !== null
                        && $request->world->server->hetzner_status !== 'deleted') {
                        throw ValidationException::withMessages(['confirmation_name' => 'Dieser Antrag nutzt einen gemeinsamen Server. Dessen Löschung muss zuerst durch die Administration geprüft werden.']);
                    }
                    $access = $request->approved_access;
                    if ($access !== null) {
                        $access['generation']++;
                    }
                    $request->forceFill(['status' => WorldRequestStatus::Terminated, 'decided_by' => $actor->id,
                        'decided_at' => now(), 'revision' => $request->revision + 1, 'approved_access' => $access,
                        'provisioning_error' => null])->save();
                    LuantiAccountJob::query()->where('world_request_id', $request->id)
                        ->whereIn('state', ['queued', 'pending', 'unknown'])->update(['state' => 'cancelled', 'error' => null]);
                    TerminateWorldRequest::dispatch($request->id)->afterCommit();
                });

                return true;
            }) === true;
        });
        if ($saved !== true) {
            throw ValidationException::withMessages(['revision' => 'Für diesen Antrag läuft gerade eine Übertragung oder Serverbereitstellung. Bitte kurz warten und erneut versuchen.']);
        }
    }

    public function process(int $requestId): void
    {
        Cache::lock('luanti:request-provisioning:'.$requestId, 1260)->get(function () use ($requestId): void {
            $request = WorldRequest::query()->find($requestId);
            if ($request === null || $request->status !== WorldRequestStatus::Terminated) {
                return;
            }
            try {
                $server = app(HetznerVirtualMachines::class)->deleteForTerminatedRequest($request);
                $request->forceFill(['provisioning_error' => $server?->hetzner_error])->save();
            } catch (ValidationException $exception) {
                $request->forceFill(['provisioning_error' => collect($exception->errors())->flatten()->first()])->save();
            } catch (Throwable $exception) {
                Log::warning('World request termination cleanup not confirmed', ['request_id' => $requestId, 'exception_type' => $exception::class]);
                $request->forceFill(['provisioning_error' => 'Die Serverlöschung konnte noch nicht bestätigt werden. Sie wird automatisch erneut geprüft.'])->save();
            }
        });
    }
}
