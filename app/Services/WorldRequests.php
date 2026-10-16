<?php

namespace App\Services;

use App\Enums\WorldRequestStatus;
use App\Models\LuantiServer;
use App\Models\LuantiWorldTemplate;
use App\Models\Organization;
use App\Models\User;
use App\Models\WorldRequest;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class WorldRequests
{
    public function __construct(private PortalAccess $access) {}

    /** @return Builder<WorldRequest> */
    public function query(User $actor, ?Organization $organization = null): Builder
    {
        Gate::forUser($this->access->currentUser($actor))->authorize('viewAny', [WorldRequest::class, $organization]);

        return WorldRequest::query()->with(['organization', 'applicant', 'world.server', 'decisionMaker', 'currentGameInstance', 'gameInstances'])
            ->when($organization !== null, fn (Builder $query): Builder => $query->where('organization_id', $organization->id));
    }

    /** @return array<int, string> */
    public function worldOptions(User $actor, ?Organization $organization = null, ?WorldRequest $request = null): array
    {
        Gate::forUser($this->access->currentUser($actor))->authorize('viewAny', [WorldRequest::class, $organization]);
        if ($request !== null) {
            Gate::forUser($this->access->currentUser($actor))->authorize('view', $request);
            abort_if($organization !== null && $request->organization_id !== $organization->id, 404);
        }

        return LuantiWorldTemplate::query()->with('server')
            ->where(fn (Builder $query): Builder => $query->where(fn (Builder $query): Builder => $query->where('is_selectable', true)->where('is_available', true))
                ->when($request !== null, fn (Builder $query): Builder => $query->orWhereKey($request->luanti_world_template_id)))
            ->orderBy('name')->get()->mapWithKeys(fn (LuantiWorldTemplate $world): array => [
                $world->id => $world->name.' ('.$world->server->name.')'.($world->is_selectable && $world->is_available ? '' : ' – Nicht mehr beantragbar'),
            ])->all();
    }

    /** @param array{name?: mixed, luanti_world_template_id?: mixed, start_date?: mixed, end_date?: mixed, participant_count?: mixed} $data */
    public function create(User $actor, Organization $organization, array $data): WorldRequest
    {
        return DB::transaction(function () use ($actor, $organization, $data): WorldRequest {
            Organization::query()->whereKey($organization->id)->lockForUpdate()->firstOrFail();
            $actor = $this->access->currentUser($actor);
            Gate::forUser($actor)->authorize('create', [WorldRequest::class, $organization]);
            $attributes = $this->validate($data);
            $this->checkWorld($attributes['luanti_world_template_id'], requireSelectable: true);
            $request = new WorldRequest($attributes);
            $request->forceFill(['organization_id' => $organization->id, 'user_id' => $actor->id,
                'status' => WorldRequestStatus::Pending, 'revision' => 1])->save();

            return $request;
        });
    }

    /** @param array{name?: mixed, luanti_world_template_id?: mixed, start_date?: mixed, end_date?: mixed, participant_count?: mixed} $data */
    public function update(User $actor, WorldRequest $request, array $data, int $expectedRevision): void
    {
        DB::transaction(function () use ($actor, $request, $data, $expectedRevision): void {
            $request = WorldRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($this->access->currentUser($actor))->authorize('update', $request);
            $this->checkRevision($request, $expectedRevision);
            $attributes = $this->validate($data, $request);
            $worldChanged = (int) $attributes['luanti_world_template_id'] !== $request->luanti_world_template_id;
            $datesChanged = $attributes['start_date'] !== $request->start_date->toDateString()
                || $attributes['end_date'] !== $request->end_date->toDateString();
            $this->checkWorld($attributes['luanti_world_template_id'], requireSelectable: $worldChanged);
            if ($worldChanged && $request->current_game_instance_id !== null) {
                throw ValidationException::withMessages(['luanti_world_template_id' => 'Bitte die Aktion Weltvorlage wechseln verwenden.']);
            }
            $request->fill($attributes);
            if ($request->status === WorldRequestStatus::Approved && $datesChanged) {
                $request->forceFill(['status' => WorldRequestStatus::Pending, 'decided_by' => null, 'decided_at' => null]);
            }
            $request->forceFill(['revision' => $request->revision + 1])->save();
        });
    }

    public function decide(User $actor, WorldRequest $request, bool $approve, int $expectedRevision): void
    {
        try {
            $serverId = $request->fresh()->world->luanti_server_id;
            Cache::lock('luanti:server:'.$serverId, 120)->block(15, function () use ($actor, $request, $approve, $expectedRevision, $serverId): void {
                Cache::lock('luanti:accounts:process:'.$request->id, 120)->block(15, function () use ($actor, $request, $approve, $expectedRevision, $serverId): void {
                    DB::transaction(function () use ($actor, $request, $approve, $expectedRevision, $serverId): void {
                        LuantiServer::query()->whereKey($serverId)->lockForUpdate()->firstOrFail();
                        $request = WorldRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
                        $actor = $this->access->currentUser($actor);
                        Gate::forUser($actor)->authorize('decide', $request);
                        $this->checkRevision($request, $expectedRevision);
                        if ($approve) {
                            $this->checkWorld($request->luanti_world_template_id, requireSelectable: true);
                            $snapshot = app(LuantiAccounts::class)->snapshot($request);
                            foreach (WorldRequest::query()->whereKeyNot($request->id)->whereNotNull('approved_access')->get() as $other) {
                                $access = $other->approved_access;
                                if ($other->status !== WorldRequestStatus::Rejected && ($access['server_id'] ?? null) === $serverId
                                    && $access['starts_at'] < $snapshot['ends_at'] && $access['ends_at'] > $snapshot['starts_at']) {
                                    throw ValidationException::withMessages(['start_date' => 'Der Server ist in diesem Zeitraum bereits für eine andere Spielinstanz reserviert.']);
                                }
                            }
                            $instance = app(LuantiGameInstances::class)->prepare($request);
                            $snapshot['instance_id'] = $request->current_game_instance_id ?? $instance->id;
                            $request->forceFill(['current_game_instance_id' => $request->current_game_instance_id ?? $instance->id, 'approved_access' => $snapshot]);
                        } elseif ($request->approved_access !== null) {
                            $access = $request->approved_access;
                            $access['generation']++;
                            $request->forceFill(['approved_access' => $access]);
                        }
                        $request->forceFill(['status' => $approve ? WorldRequestStatus::Approved : WorldRequestStatus::Rejected,
                            'decided_by' => $actor->id, 'decided_at' => now(), 'revision' => $request->revision + 1])->save();
                        if ($request->approved_access !== null) {
                            app(LuantiAccounts::class)->cancelSupersededUnsentJobs($request);
                        }

                    });
                });
            });
        } catch (LockTimeoutException) {
            throw ValidationException::withMessages(['revision' => 'Spielaccounts werden gerade übertragen. Bitte versuche die Entscheidung in wenigen Sekunden erneut.']);
        }
    }

    public function changeTemplate(User $actor, WorldRequest $request, int $templateId, int $expectedRevision): void
    {
        Cache::lock('luanti:accounts:process:'.$request->id, 120)->block(15, function () use ($actor, $request, $templateId, $expectedRevision): void {
            DB::transaction(function () use ($actor, $request, $templateId, $expectedRevision): void {
                $request = WorldRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
                Gate::forUser($this->access->currentUser($actor))->authorize('update', $request);
                $this->checkRevision($request, $expectedRevision);
                $template = LuantiWorldTemplate::query()->findOrFail($templateId);
                if ($request->status !== WorldRequestStatus::Approved || $request->approved_access === null
                    || $template->luanti_server_id !== $request->approved_access['server_id']) {
                    throw ValidationException::withMessages(['luanti_world_template_id' => 'Bitte eine Weltvorlage auf dem genehmigten Server wählen.']);
                }
                if ($request->gameInstances()->where('is_archived', false)->whereKeyNot($request->current_game_instance_id)->exists()) {
                    throw ValidationException::withMessages(['luanti_world_template_id' => 'Ein Vorlagenwechsel wird bereits vorbereitet. Bitte zuerst diesen Auftrag abschließen.']);
                }
                if ($templateId === $request->luanti_world_template_id) {
                    return;
                }
                $request->forceFill(['luanti_world_template_id' => $templateId, 'revision' => $request->revision + 1])->save();
                $request->unsetRelation('world');
                app(LuantiGameInstances::class)->prepare($request);
            });
        });
    }

    private function checkRevision(WorldRequest $request, int $expectedRevision): void
    {
        if ($request->revision !== $expectedRevision) {
            throw ValidationException::withMessages(['revision' => 'Der Antrag wurde inzwischen geändert. Bitte schließe das Formular und öffne es erneut.']);
        }
    }

    private function checkWorld(int|string $worldId, bool $requireSelectable): void
    {
        $world = LuantiWorldTemplate::query()->whereKey($worldId)->lockForUpdate()->first();
        if ($world === null || ($requireSelectable && (! $world->is_selectable || ! $world->is_available))) {
            throw ValidationException::withMessages(['luanti_world_template_id' => 'Bitte wähle eine aktuell beantragbare Welt.']);
        }
    }

    /**
     * @param  array{name?: mixed, luanti_world_template_id?: mixed, start_date?: mixed, end_date?: mixed, participant_count?: mixed}  $data
     * @return array{name: string, luanti_world_template_id: int|string, start_date: string, end_date: string, participant_count: int|string}
     */
    private function validate(array $data, ?WorldRequest $request = null): array
    {
        if (is_string($data['name'] ?? null)) {
            $data['name'] = trim($data['name']);
        }
        $datesChanged = $request === null || ($data['start_date'] ?? null) !== $request->start_date->toDateString()
            || ($data['end_date'] ?? null) !== $request->end_date->toDateString();

        return Validator::make($data, [
            'name' => ['required', 'string', 'max:255'],
            'luanti_world_template_id' => ['required', 'integer', Rule::exists('luanti_world_templates', 'id')],
            'start_date' => ['required', 'date_format:Y-m-d', ...($datesChanged ? ['after_or_equal:'.now('Europe/Berlin')->toDateString()] : [])],
            'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'participant_count' => ['required', 'integer', 'min:1', 'max:4294967295'],
        ], [], ['name' => 'Name', 'luanti_world_template_id' => 'Welt', 'start_date' => 'Startdatum',
            'end_date' => 'Enddatum', 'participant_count' => 'Teilnehmendenzahl'])->validate();
    }
}
