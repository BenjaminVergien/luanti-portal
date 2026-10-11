<?php

namespace App\Services;

use App\Enums\InternalRole;
use App\Enums\JoinRequestStatus;
use App\Enums\MembershipRole;
use App\Mail\PortalInvitation;
use App\Models\Invitation;
use App\Models\JoinRequest;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use Illuminate\Cache\RateLimiter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PortalAccess
{
    public function currentUser(User $user): User
    {
        $current = User::query()->when(DB::transactionLevel() > 0, fn (Builder $query): Builder => $query->lockForUpdate())->findOrFail($user->id);
        abort_unless($current->is_active && $current->hasVerifiedEmail(), 403);

        return $current;
    }

    public function createOrganization(User $actor, string $name): Organization
    {
        Gate::forUser($this->currentUser($actor))->authorize('create', Organization::class);

        return Organization::query()->create($this->validateName($name));
    }

    public function renameOrganization(User $actor, Organization $organization, string $name): void
    {
        Gate::forUser($this->currentUser($actor))->authorize('update', $organization);
        $organization->update($this->validateName($name));
    }

    private function validateName(string $name): array
    {
        return Validator::make(['name' => trim($name)], ['name' => ['required', 'string', 'max:255']])->validate();
    }

    public function assignMembership(User $actor, Organization $organization, User $target, MembershipRole $role): OrganizationMembership
    {
        return DB::transaction(function () use ($actor, $organization, $target, $role): OrganizationMembership {
            Organization::query()->whereKey($organization->id)->lockForUpdate()->firstOrFail();
            $actor = $this->currentUser($actor);
            Gate::forUser($actor)->authorize('assign', [OrganizationMembership::class, $organization, $role]);
            $target = $this->currentUser($target);
            $existing = OrganizationMembership::query()->where('organization_id', $organization->id)->where('user_id', $target->id)->first();
            if ($existing !== null) {
                Gate::forUser($actor)->authorize('update', $existing);
            }

            $membership = OrganizationMembership::query()->updateOrCreate(['organization_id' => $organization->id, 'user_id' => $target->id], ['role' => $role]);
            JoinRequest::query()->where('organization_id', $organization->id)->where('user_id', $target->id)->where('status', JoinRequestStatus::Pending)
                ->update(['status' => JoinRequestStatus::Approved->value, 'decided_by' => $actor->id, 'decided_at' => now()]);

            return $membership;
        });
    }

    public function assignByEmail(User $actor, Organization $organization, string $email, MembershipRole $role): OrganizationMembership
    {
        Gate::forUser($this->currentUser($actor))->authorize('assign', [OrganizationMembership::class, $organization, $role]);
        $target = User::query()->where('email', Str::lower(trim($email)))->first();
        if ($target === null || ! $target->is_active || ! $target->hasVerifiedEmail()) {
            throw ValidationException::withMessages(['email' => 'Ein aktives Konto mit bestätigter E-Mail-Adresse ist erforderlich. Alternativ eine Einladung senden.']);
        }

        return $this->assignMembership($actor, $organization, $target, $role);
    }

    public function removeMembership(User $actor, OrganizationMembership $membership): void
    {
        DB::transaction(function () use ($actor, $membership): void {
            Organization::query()->whereKey($membership->organization_id)->lockForUpdate()->firstOrFail();
            $membership = OrganizationMembership::query()->findOrFail($membership->id);
            Gate::forUser($this->currentUser($actor))->authorize('delete', $membership);
            $membership->delete();
        });
    }

    public function requestMembership(User $actor, Organization $organization): JoinRequest
    {
        return DB::transaction(function () use ($actor, $organization): JoinRequest {
            Organization::query()->whereKey($organization->id)->lockForUpdate()->firstOrFail();
            $actor = $this->currentUser($actor);
            Gate::forUser($actor)->authorize('create', JoinRequest::class);
            if ($actor->memberships()->where('organization_id', $organization->id)->exists()) {
                throw ValidationException::withMessages(['organization' => 'Du bist bereits Mitglied dieser Einrichtung.']);
            }
            if (JoinRequest::query()->where('organization_id', $organization->id)->where('user_id', $actor->id)->where('status', JoinRequestStatus::Pending)->exists()) {
                throw ValidationException::withMessages(['organization' => 'Für diese Einrichtung besteht bereits ein offener Antrag.']);
            }

            return JoinRequest::query()->create(['organization_id' => $organization->id, 'user_id' => $actor->id, 'status' => JoinRequestStatus::Pending]);
        });
    }

    public function decideRequest(User $actor, JoinRequest $joinRequest, bool $approve): void
    {
        DB::transaction(function () use ($actor, $joinRequest, $approve): void {
            Organization::query()->whereKey($joinRequest->organization_id)->lockForUpdate()->firstOrFail();
            $joinRequest = JoinRequest::query()->lockForUpdate()->findOrFail($joinRequest->id);
            $actor = $this->currentUser($actor);
            Gate::forUser($actor)->authorize('decide', $joinRequest);
            if ($approve) {
                $target = $this->currentUser($joinRequest->user);
                OrganizationMembership::query()->firstOrCreate(
                    ['organization_id' => $joinRequest->organization_id, 'user_id' => $target->id], ['role' => MembershipRole::Staff]);
            }
            $joinRequest->update(['status' => $approve ? JoinRequestStatus::Approved : JoinRequestStatus::Rejected, 'decided_by' => $actor->id, 'decided_at' => now()]);
        });
    }

    public function cancelRequest(User $actor, JoinRequest $joinRequest): void
    {
        DB::transaction(function () use ($actor, $joinRequest): void {
            $joinRequest = JoinRequest::query()->lockForUpdate()->findOrFail($joinRequest->id);
            $actor = $this->currentUser($actor);
            Gate::forUser($actor)->authorize('cancel', $joinRequest);
            $joinRequest->update(['status' => JoinRequestStatus::Cancelled, 'decided_by' => $actor->id, 'decided_at' => now()]);
        });
    }

    public function invite(User $actor, string $email, ?Organization $organization = null, ?MembershipRole $membershipRole = null, ?InternalRole $internalRole = null): Invitation
    {
        $actor = $this->currentUser($actor);
        Gate::forUser($actor)->authorize('create', [Invitation::class, $organization, $membershipRole, $internalRole]);
        $email = Str::lower(trim($email));
        Validator::make(['email' => $email], ['email' => ['required', 'email', 'max:255']])->validate();
        $token = Str::random(64);
        $cache = Cache::store(config('invitations.cache_store'));
        $invitation = $cache->lock('portal:invitations:issue', 30)->get(function () use ($cache, $actor, $email, $organization, $membershipRole, $internalRole, $token): Invitation {
            return DB::transaction(function () use ($cache, $actor, $email, $organization, $membershipRole, $internalRole, $token): Invitation {
                if ($organization !== null) {
                    Organization::query()->whereKey($organization->id)->lockForUpdate()->firstOrFail();
                }
                $actor = $this->currentUser($actor);
                Gate::forUser($actor)->authorize('create', [Invitation::class, $organization, $membershipRole, $internalRole]);

                $duplicate = Invitation::query()->where('email', $email)->where('organization_id', $organization?->id)
                    ->where('membership_role', $membershipRole)->where('internal_role', $internalRole)
                    ->whereNull('accepted_at')->whereNull('revoked_at')->where('expires_at', '>', now())->exists();
                if ($duplicate) {
                    throw ValidationException::withMessages(['email' => 'Für diese E-Mail-Adresse besteht bereits eine offene Einladung '.($organization === null ? 'für diese interne Rolle.' : 'für diese Einrichtung und Rolle.')]);
                }

                $limiter = new RateLimiter($cache);
                $limits = $this->invitationLimits($actor, $email, $organization);
                $wait = 0;
                foreach ($limits as $limit) {
                    if ($limiter->tooManyAttempts($limit->key, $limit->maxAttempts)) {
                        $wait = max($wait, 1, $limiter->availableIn($limit->key));
                    }
                }
                if ($wait > 0) {
                    $minutes = (int) ceil($wait / 60);
                    throw ValidationException::withMessages(['email' => 'Das Versandlimit für Einladungen ist erreicht. Bitte in '.$minutes.($minutes === 1 ? ' Minute' : ' Minuten').' erneut versuchen.']);
                }

                foreach ($limits as $limit) {
                    $limiter->hit($limit->key, $limit->decaySeconds);
                }

                return Invitation::query()->create([
                    'email' => $email, 'organization_id' => $organization?->id, 'membership_role' => $membershipRole,
                    'internal_role' => $internalRole, 'invited_by' => $actor->id,
                    'token_hash' => hash('sha256', $token), 'expires_at' => now()->addDays(7),
                ]);
            });
        });
        if ($invitation === false) {
            throw ValidationException::withMessages(['email' => 'Der Einladungsversand ist gerade ausgelastet. Bitte in wenigen Sekunden erneut versuchen.']);
        }

        try {
            Mail::to($email)->send(new PortalInvitation($invitation, route('invitations.open', ['token' => $token])));
        } catch (\Throwable $exception) {
            $invitation->update(['revoked_at' => now()]);
            throw $exception;
        }

        return $invitation;
    }

    /** @return list<Limit> */
    private function invitationLimits(User $actor, string $email, ?Organization $organization): array
    {
        $prefix = 'portal:invitations:';
        $recipient = hash('sha256', $email);
        $limits = [
            Limit::perMinute(max(1, config('invitations.limits.actor_per_minute')))->by($prefix.'actor:'.$actor->id.':minute'),
            Limit::perHour(max(1, config('invitations.limits.actor_per_hour')))->by($prefix.'actor:'.$actor->id.':hour'),
            Limit::perHour(max(1, config('invitations.limits.recipient_per_hour')))->by($prefix.'recipient:'.$recipient.':hour'),
            Limit::perDay(max(1, config('invitations.limits.recipient_per_day')))->by($prefix.'recipient:'.$recipient.':day'),
            Limit::perMinute(max(1, config('invitations.limits.global_per_minute')))->by($prefix.'global:minute'),
            Limit::perHour(max(1, config('invitations.limits.global_per_hour')))->by($prefix.'global:hour'),
        ];
        if ($organization !== null) {
            $limits[] = Limit::perHour(max(1, config('invitations.limits.organization_per_hour')))->by($prefix.'organization:'.$organization->id.':hour');
        }

        return $limits;
    }

    public function revokeInvitation(User $actor, Invitation $invitation): void
    {
        DB::transaction(function () use ($actor, $invitation): void {
            $invitation = Invitation::query()->lockForUpdate()->findOrFail($invitation->id);
            Gate::forUser($this->currentUser($actor))->authorize('revoke', $invitation);
            $invitation->update(['revoked_at' => now()]);
        });
    }

    public function acceptInvitation(User $actor, #[\SensitiveParameter] string $token): Invitation
    {
        return $this->acceptInvitationHash($actor, hash('sha256', $token));
    }

    public function acceptInvitationHash(User $actor, string $tokenHash): Invitation
    {
        return DB::transaction(function () use ($actor, $tokenHash): Invitation {
            $invitation = Invitation::query()->where('token_hash', $tokenHash)->first();
            if ($invitation?->organization_id !== null) {
                Organization::query()->whereKey($invitation->organization_id)->lockForUpdate()->firstOrFail();
            }
            $invitation = Invitation::query()->where('token_hash', $tokenHash)->lockForUpdate()->first();
            if ($invitation === null || ! $invitation->isPending()) {
                throw ValidationException::withMessages(['invitation' => 'Diese Einladung ist ungültig, abgelaufen oder bereits erledigt.']);
            }
            $actor = $this->currentUser($actor);
            if (Str::lower($actor->email) !== $invitation->email) {
                throw ValidationException::withMessages(['invitation' => 'Bitte mit dem Konto der eingeladenen E-Mail-Adresse anmelden.']);
            }
            $inviter = $this->currentUser($invitation->inviter);
            Gate::forUser($inviter)->authorize('create', [Invitation::class, $invitation->organization, $invitation->membership_role, $invitation->internal_role]);
            if ($invitation->organization_id !== null) {
                $membership = OrganizationMembership::query()->firstOrCreate(
                    ['organization_id' => $invitation->organization_id, 'user_id' => $actor->id], ['role' => $invitation->membership_role]);
                if ($membership->role === MembershipRole::Staff && $invitation->membership_role === MembershipRole::Manager) {
                    $membership->update(['role' => MembershipRole::Manager]);
                }
                JoinRequest::query()->where('organization_id', $invitation->organization_id)->where('user_id', $actor->id)->where('status', JoinRequestStatus::Pending)
                    ->update(['status' => JoinRequestStatus::Approved->value, 'decided_by' => $inviter->id, 'decided_at' => now()]);
            } else {
                $actor = User::query()->lockForUpdate()->findOrFail($actor->id);
                if ($actor->internal_role !== InternalRole::GlobalAdmin) {
                    $actor->forceFill(['internal_role' => $invitation->internal_role])->save();
                }
            }
            $invitation->update(['accepted_at' => now(), 'accepted_by' => $actor->id]);

            return $invitation;
        });
    }

    /** @param array{name?: mixed, email?: mixed, password?: mixed, password_confirmation?: mixed, is_active?: mixed, email_verified?: mixed} $data */
    public function createAccount(User $actor, #[\SensitiveParameter] array $data): User
    {
        return DB::transaction(function () use ($actor, $data): User {
            Gate::forUser($this->currentUser($actor))->authorize('create', User::class);
            $attributes = $this->validateAccountDetails($data);
            $user = new User(collect($attributes)->only(['name', 'email', 'password'])->all());
            $user->forceFill([
                'is_active' => (bool) ($attributes['is_active'] ?? true),
                'email_verified_at' => ($attributes['email_verified'] ?? false) ? now() : null,
                'internal_role' => null,
            ])->save();

            return $user;
        });
    }

    /** @param array{name?: mixed, email?: mixed, password?: mixed, password_confirmation?: mixed, email_verified?: mixed} $details */
    public function updateAccount(User $actor, User $target, ?InternalRole $role, bool $active, #[\SensitiveParameter] array $details = []): void
    {
        DB::transaction(function () use ($actor, $target, $role, $active, $details): void {
            $admins = User::query()->where('internal_role', InternalRole::GlobalAdmin)->orderBy('id')->lockForUpdate()->get();
            $actor = $this->currentUser($actor);
            $target = User::query()->lockForUpdate()->findOrFail($target->id);
            Gate::forUser($actor)->authorize('update', $target);
            $attributes = $this->validateAccountDetails($details, $target);
            $emailChanged = isset($attributes['email']) && $attributes['email'] !== $target->email;
            $verified = (bool) ($attributes['email_verified'] ?? (! $emailChanged && $target->hasVerifiedEmail()));
            $verifiedAt = $verified ? (! $emailChanged && $target->hasVerifiedEmail() ? $target->email_verified_at : now()) : null;
            if ($target->isGlobalAdmin() && (! $active || ! $verified || $role !== InternalRole::GlobalAdmin) && $admins->filter(fn (User $admin): bool => $admin->isGlobalAdmin())->count() <= 1) {
                throw ValidationException::withMessages(['internal_role' => 'Der letzte aktive Globaladmin muss erhalten bleiben.']);
            }
            if ($role !== null && ! $verified) {
                throw ValidationException::withMessages(['internal_role' => 'Interne Rollen benötigen eine bestätigte E-Mail-Adresse.']);
            }
            if ($target->internal_role === null && $role !== null) {
                throw ValidationException::withMessages(['internal_role' => 'Neue interne Rollen werden per Einladung vergeben.']);
            }
            if ($target->internal_role === InternalRole::Caseworker && $role === InternalRole::GlobalAdmin) {
                throw ValidationException::withMessages(['internal_role' => 'Die Globaladminrolle wird per Einladung vergeben.']);
            }
            if (blank($attributes['password'] ?? null)) {
                unset($attributes['password']);
            }
            $target->fill(collect($attributes)->only(['name', 'email', 'password'])->all());
            $target->forceFill(['internal_role' => $role, 'is_active' => $active, 'email_verified_at' => $verifiedAt])->save();
        });
    }

    /**
     * @param  array{name?: mixed, email?: mixed, password?: mixed, password_confirmation?: mixed, is_active?: mixed, email_verified?: mixed}  $data
     * @return array{name?: string, email?: string, password?: ?string, is_active?: bool|int|string, email_verified?: bool|int|string}
     */
    private function validateAccountDetails(#[\SensitiveParameter] array $data, ?User $target = null): array
    {
        if (is_string($data['name'] ?? null)) {
            $data['name'] = trim($data['name']);
        }
        if (is_string($data['email'] ?? null)) {
            $data['email'] = Str::lower(trim($data['email']));
        }
        if ($target !== null && ($data['password'] ?? null) === '') {
            $data['password'] = null;
        }

        $presenceRules = $target === null ? ['required'] : ['sometimes', 'required'];

        return Validator::make($data, [
            'name' => [...$presenceRules, 'string', 'max:255'],
            'email' => [...$presenceRules, 'email', 'max:255', Rule::unique(User::class, 'email')->ignore($target?->id)],
            'password' => [$target === null ? 'required' : 'nullable', 'string', 'min:12', 'confirmed'],
            'is_active' => ['sometimes', 'boolean'],
            'email_verified' => ['sometimes', 'boolean'],
        ])->validate();
    }
}
