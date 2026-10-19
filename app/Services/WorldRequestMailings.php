<?php

namespace App\Services;

use App\Enums\WorldRequestStatus;
use App\Filament\Pages\LuantiAccounts as AccountsPage;
use App\Filament\Pages\WorldRequests as RequestsPage;
use App\Jobs\SendWorldRequestMail;
use App\Mail\WorldRequestStatusMail;
use App\Models\WorldRequest;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use stdClass;
use Throwable;

class WorldRequestMailings
{
    public function queueApproval(WorldRequest $request): void
    {
        if ($request->status !== WorldRequestStatus::Approved || $request->approval_mail_revision === null) {
            return;
        }
        $this->enqueue($request, 'approval', 'approval:'.$request->approval_mail_revision);
    }

    public function ensureFacilitatorAccount(WorldRequest $request): void
    {
        DB::transaction(function () use ($request): void {
            $request = WorldRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
            if ($request->facilitator_account_id !== null || $request->provisioning_configuration === null
                || $request->status !== WorldRequestStatus::Approved || $request->current_game_instance_id === null) {
                return;
            }
            if (! Gate::forUser($request->applicant)->allows('manageAccounts', $request)) {
                throw ValidationException::withMessages(['provisioning' => 'Die beantragende Person benötigt einen aktiven, bestätigten Einrichtungszugang für den Fachkraft-Spielaccount.']);
            }
            $username = 'fk_'.strtolower(Str::random(12));
            app(LuantiAccounts::class)->create($request->applicant, $request, [[
                'username' => $username, 'password' => Str::password(24), 'role' => 'facilitator',
            ]]);
            $account = $request->luantiAccounts()->where('normalized_username', $username)->sole();
            $request->forceFill(['facilitator_account_id' => $account->id])->save();
        });
    }

    public function queueReadyIfEnterable(int $requestId): void
    {
        DB::transaction(function () use ($requestId): void {
            $request = WorldRequest::query()->whereKey($requestId)->lockForUpdate()->firstOrFail();
            $access = $request->approved_access;
            $instance = $request->currentGameInstance;
            $account = $request->facilitatorAccount;
            if ($request->status !== WorldRequestStatus::Approved || $access === null || $instance === null || $account === null
                || $account->world_request_id !== $request->id || $account->role !== 'facilitator'
                || $instance->is_archived || ! $instance->is_prepared || $instance->state !== 'running' || $instance->error !== null
                || now()->timestamp < $access['starts_at'] || now()->timestamp >= $access['ends_at']) {
                return;
            }
            $server = $instance->server;
            if ($server->trashed() || ($server->hetzner_reference !== null && ($server->hetzner_status !== 'running'
                || $server->bridge_status !== 'installed' || $server->luanti_installation_status !== 'verified'))) {
                return;
            }
            $assignment = $account->assignments()->where('luanti_game_instance_id', $instance->id)->first();
            $window = ['starts_at' => $access['starts_at'], 'ends_at' => $access['ends_at']];
            if ($assignment === null || ! $assignment->is_provisioned || ! $assignment->applied_admitted
                || $assignment->error !== null || $assignment->outcome_uncertain || $assignment->applied_window !== $window) {
                return;
            }
            $this->enqueue($request, 'ready', 'ready:'.$instance->id.':'.($request->approval_mail_revision ?? 0));
        });
    }

    private function enqueue(WorldRequest $request, string $kind, string $eventKey): void
    {
        $starts = $request->provisioning_starts_at ?? CarbonImmutable::parse($request->start_date->toDateString(), 'Europe/Berlin')->startOfDay();
        $payload = [
            'recipientName' => $request->applicant->name, 'requestName' => $request->name,
            'organizationName' => $request->organization->name, 'startDate' => $request->start_date->format('d.m.Y'),
            'endDate' => $request->end_date->format('d.m.Y'), 'provisioningAt' => $starts->setTimezone('Europe/Berlin')->format('d.m.Y H:i'),
            'startsImmediately' => $starts->lessThanOrEqualTo(now()),
            'portalUrl' => $kind === 'approval'
                ? RequestsPage::getUrl(panel: 'portal', tenant: $request->organization)
                : AccountsPage::getUrl(['worldRequest' => $request->id], panel: 'portal', tenant: $request->organization),
        ];
        DB::table('world_request_mails')->insertOrIgnore([
            'world_request_id' => $request->id, 'event_key' => $eventKey, 'kind' => $kind,
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $mail = DB::table('world_request_mails')->where('world_request_id', $request->id)->where('event_key', $eventKey)->first();
        if ($mail->accepted_at === null) {
            SendWorldRequestMail::dispatch($mail->id)->afterCommit();
        }
    }

    public function recover(): void
    {
        DB::table('world_request_mails')->whereNull('accepted_at')->where('attempts', '<', 5)
            ->where(fn (Builder $query): Builder => $query->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
            ->orderBy('id')->each(function (stdClass $mail): void {
                SendWorldRequestMail::dispatch($mail->id);
            });
    }

    public function deliver(int $mailId): void
    {
        Cache::lock('world-request-mail:'.$mailId, 60)->get(function () use ($mailId): void {
            $mail = DB::table('world_request_mails')->where('id', $mailId)->first();
            if ($mail === null || $mail->accepted_at !== null || $mail->attempts >= 5) {
                return;
            }
            DB::table('world_request_mails')->where('id', $mailId)->update([
                'attempts' => $mail->attempts + 1, 'next_attempt_at' => now()->addMinutes(5), 'updated_at' => now(),
            ]);
            try {
                $request = WorldRequest::query()->findOrFail($mail->world_request_id);
                if ($request->status === WorldRequestStatus::Terminated) {
                    DB::table('world_request_mails')->where('id', $mailId)->update([
                        'attempts' => 5, 'next_attempt_at' => null, 'last_error' => 'Der Weltantrag wurde beendet. Die Status-E-Mail wird nicht mehr versendet.',
                    ]);

                    return;
                }
                Mail::to($request->applicant->email)->send(new WorldRequestStatusMail($mail->kind,
                    json_decode($mail->payload, true, flags: JSON_THROW_ON_ERROR)));
                DB::table('world_request_mails')->where('id', $mailId)->update([
                    'accepted_at' => now(), 'next_attempt_at' => null, 'last_error' => null, 'updated_at' => now(),
                ]);
            } catch (Throwable $exception) {
                Log::warning('World request mail not accepted', ['mail_id' => $mailId, 'exception_type' => $exception::class]);
                DB::table('world_request_mails')->where('id', $mailId)->update([
                    'last_error' => 'Die Status-E-Mail konnte nicht an den Mailserver übergeben werden.', 'updated_at' => now(),
                ]);
                throw new RuntimeException('Die Status-E-Mail konnte nicht an den Mailserver übergeben werden.');
            }
        });
    }
}
