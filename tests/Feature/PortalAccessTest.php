<?php

namespace Tests\Feature;

use App\Enums\InternalRole;
use App\Enums\JoinRequestStatus;
use App\Enums\MembershipRole;
use App\Mail\PortalInvitation;
use App\Models\Invitation;
use App\Models\JoinRequest;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Services\PortalAccess;
use Database\Seeders\LocalDevelopmentSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class PortalAccessTest extends TestCase
{
    use RefreshDatabase;

    private PortalAccess $access;

    private Organization $organization;

    private User $admin;

    private User $caseworker;

    private User $manager;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->access = app(PortalAccess::class);
        $this->organization = Organization::factory()->create();
        $this->admin = User::factory()->globalAdmin()->create();
        $this->caseworker = User::factory()->caseworker()->create();
        $this->manager = User::factory()->create();
        $this->staff = User::factory()->create();
        OrganizationMembership::factory()->manager()->create(['organization_id' => $this->organization->id, 'user_id' => $this->manager->id]);
        OrganizationMembership::factory()->create(['organization_id' => $this->organization->id, 'user_id' => $this->staff->id]);
    }

    public static function roles(): array
    {
        return [
            'global admin' => ['admin', true, true, true, true],
            'caseworker' => ['caseworker', false, false, true, true],
            'organization manager' => ['manager', false, true, false, true],
            'staff' => ['staff', false, false, false, false],
        ];
    }

    #[DataProvider('roles')]
    public function test_role_matrix(string $actorName, bool $create, bool $rename, bool $assignManager, bool $assignStaff): void
    {
        $gate = Gate::forUser($this->{$actorName});
        $this->assertSame($create, $gate->allows('create', Organization::class));
        $this->assertSame($rename, $gate->allows('update', $this->organization));
        $this->assertSame($assignManager, $gate->allows('assign', [OrganizationMembership::class, $this->organization, MembershipRole::Manager]));
        $this->assertSame($assignStaff, $gate->allows('assign', [OrganizationMembership::class, $this->organization, MembershipRole::Staff]));
        $this->assertSame($actorName === 'admin', $gate->allows('create', [Invitation::class, null, null, InternalRole::Caseworker]));
    }

    public function test_admin_creates_and_manager_renames_an_organization(): void
    {
        $organization = $this->access->createOrganization($this->admin, ' Neue Einrichtung ');
        $this->assertSame('Neue Einrichtung', $organization->name);
        $this->access->renameOrganization($this->manager, $this->organization, 'Geänderter Name');
        $this->assertSame('Geänderter Name', $this->organization->fresh()->name);
    }

    public function test_caseworker_cannot_change_organization_configuration(): void
    {
        $this->expectException(AuthorizationException::class);
        $this->access->renameOrganization($this->caseworker, $this->organization, 'Unzulässig');
    }

    public function test_caseworker_assigns_a_manager_to_another_organization(): void
    {
        $organization = Organization::factory()->create();
        $membership = $this->access->assignMembership($this->caseworker, $organization, $this->staff, MembershipRole::Manager);
        $this->assertSame(MembershipRole::Manager, $membership->role);
        $this->assertSame(2, $this->staff->memberships()->count());
    }

    public function test_manager_cannot_promote_a_staff_member(): void
    {
        $this->expectException(AuthorizationException::class);
        $this->access->assignMembership($this->manager, $this->organization, $this->staff, MembershipRole::Manager);
    }

    public function test_manager_cannot_demote_or_remove_a_manager(): void
    {
        $membership = $this->manager->memberships()->first();
        $this->assertFalse(Gate::forUser($this->manager)->allows('delete', $membership));
        $this->expectException(AuthorizationException::class);
        $this->access->assignMembership($this->manager, $this->organization, $this->manager, MembershipRole::Staff);
    }

    public function test_removal_preserves_other_memberships_and_the_account(): void
    {
        $other = Organization::factory()->create();
        OrganizationMembership::factory()->create(['organization_id' => $other->id, 'user_id' => $this->staff->id]);
        $this->access->removeMembership($this->manager, $this->staff->memberships()->where('organization_id', $this->organization->id)->first());
        $this->assertSame([$other->id], $this->staff->memberships()->pluck('organization_id')->all());
        $this->assertTrue($this->staff->fresh()->is_active);
    }

    public function test_stale_manager_object_loses_authority_after_removal(): void
    {
        $this->manager->memberships()->delete();
        $this->expectException(AuthorizationException::class);
        $this->access->assignMembership($this->manager, $this->organization, User::factory()->create(), MembershipRole::Staff);
    }

    public function test_unverified_accounts_cannot_be_assigned(): void
    {
        $this->expectException(HttpException::class);
        $this->access->assignMembership($this->admin, $this->organization, User::factory()->unverified()->create(), MembershipRole::Staff);
    }

    public function test_duplicate_requests_are_blocked_without_granting_access(): void
    {
        $applicant = User::factory()->create();
        $this->access->requestMembership($applicant, $this->organization);
        $this->assertSame(0, $applicant->memberships()->count());
        try {
            $this->access->requestMembership($applicant, $this->organization);
            $this->fail('Duplicate request accepted');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('organization', $exception->errors());
        }
        $this->assertSame(1, JoinRequest::query()->count());
    }

    public function test_rejected_request_can_be_reapplied_and_approved(): void
    {
        $applicant = User::factory()->create();
        $first = $this->access->requestMembership($applicant, $this->organization);
        $this->access->decideRequest($this->manager, $first, false);
        $second = $this->access->requestMembership($applicant, $this->organization);
        $this->access->decideRequest($this->caseworker, $second, true);
        $this->assertSame(JoinRequestStatus::Rejected, $first->fresh()->status);
        $this->assertSame(JoinRequestStatus::Approved, $second->fresh()->status);
        $this->assertSame($this->caseworker->id, $second->fresh()->decided_by);
        $this->assertSame(MembershipRole::Staff, $applicant->memberships()->first()->role);
    }

    public function test_request_approval_does_not_downgrade_an_existing_manager(): void
    {
        $request = JoinRequest::factory()->create(['organization_id' => $this->organization->id, 'user_id' => $this->manager->id]);
        $this->access->decideRequest($this->admin, $request, true);
        $this->assertSame(MembershipRole::Manager, $this->manager->memberships()->first()->role);
    }

    public function test_direct_assignment_also_resolves_the_open_join_request(): void
    {
        $applicant = User::factory()->create();
        $request = $this->access->requestMembership($applicant, $this->organization);
        $this->access->assignMembership($this->caseworker, $this->organization, $applicant, MembershipRole::Manager);
        $this->assertSame(JoinRequestStatus::Approved, $request->fresh()->status);
        $this->assertSame($this->caseworker->id, $request->fresh()->decided_by);
        $this->assertSame(MembershipRole::Manager, $applicant->memberships()->first()->role);
    }

    public function test_manager_cannot_decide_requests_for_another_organization(): void
    {
        $request = JoinRequest::factory()->create();
        $this->expectException(AuthorizationException::class);
        $this->access->decideRequest($this->manager, $request, true);
    }

    public function test_only_applicant_can_cancel_an_open_request(): void
    {
        $request = $this->access->requestMembership(User::factory()->create(), $this->organization);
        $this->assertFalse(Gate::forUser($this->manager)->allows('cancel', $request));
        $this->access->cancelRequest($request->user, $request);
        $this->assertSame(JoinRequestStatus::Cancelled, $request->fresh()->status);
        $this->assertNotNull($request->fresh()->decided_at);
    }

    private function inviteStaff(User $target, ?MembershipRole $role = null): array
    {
        $invitation = $this->access->invite($this->admin, $target->email, $this->organization, $role ?? MembershipRole::Staff);
        $mail = Mail::sent(PortalInvitation::class)->last();

        return [$invitation, basename(parse_url($mail->acceptUrl, PHP_URL_PATH))];
    }

    public function test_invitation_is_personal_hashed_and_single_use(): void
    {
        $target = User::factory()->create();
        [$invitation, $token] = $this->inviteStaff($target);
        $this->assertSame(hash('sha256', $token), $invitation->token_hash);
        $this->assertArrayNotHasKey('token_hash', $invitation->toArray());
        $this->assertStringNotContainsString($token, $invitation->toJson());
        $this->access->acceptInvitation($target, $token);
        $this->assertSame($target->id, $invitation->fresh()->accepted_by);
        $this->assertSame(1, $target->memberships()->count());
        $this->expectException(ValidationException::class);
        $this->access->acceptInvitation($target, $token);
    }

    public function test_wrong_email_cannot_accept_an_invitation(): void
    {
        [$invitation, $token] = $this->inviteStaff(User::factory()->create());
        try {
            $this->access->acceptInvitation($this->staff, $token);
            $this->fail('Wrong email accepted');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('invitation', $exception->errors());
        }
        $this->assertNull($invitation->fresh()->accepted_at);
    }

    public function test_expired_invitation_cannot_be_accepted(): void
    {
        $target = User::factory()->create();
        [$invitation, $token] = $this->inviteStaff($target);
        $this->travel(8)->days();
        $this->expectException(ValidationException::class);
        $this->access->acceptInvitation($target, $token);
    }

    public function test_unverified_recipient_cannot_accept_an_invitation(): void
    {
        $target = User::factory()->unverified()->create();
        [$invitation, $token] = $this->inviteStaff($target);
        try {
            $this->access->acceptInvitation($target, $token);
            $this->fail('Unverified recipient accepted');
        } catch (HttpException) {
            $this->assertNull($invitation->fresh()->accepted_at);
        }
        $this->assertSame(0, $target->memberships()->count());
    }

    public function test_suspended_inviter_cannot_grant_membership(): void
    {
        $target = User::factory()->create();
        [$invitation, $token] = $this->inviteStaff($target);
        $this->admin->forceFill(['is_active' => false])->save();
        try {
            $this->access->acceptInvitation($target, $token);
            $this->fail('Suspended inviter accepted');
        } catch (HttpException) {
            $this->assertNull($invitation->fresh()->accepted_at);
        }
        $this->assertSame(0, $target->memberships()->count());
    }

    public function test_suspended_second_admin_does_not_allow_removing_the_last_active_admin(): void
    {
        User::factory()->globalAdmin()->inactive()->create();
        $this->expectException(ValidationException::class);
        $this->access->updateAccount($this->admin, $this->admin, null, false);
    }

    public function test_revoked_invitation_cannot_be_accepted(): void
    {
        $target = User::factory()->create();
        [$invitation, $token] = $this->inviteStaff($target);
        $this->access->revokeInvitation($this->caseworker, $invitation);
        $this->expectException(ValidationException::class);
        $this->access->acceptInvitation($target, $token);
    }

    public function test_staff_invitation_does_not_downgrade_a_manager(): void
    {
        [$invitation, $token] = $this->inviteStaff($this->manager);
        $this->access->acceptInvitation($this->manager, $token);
        $this->assertSame(MembershipRole::Manager, $this->manager->memberships()->first()->role);
    }

    public function test_manager_invitation_promotes_staff_and_resolves_pending_request(): void
    {
        $target = User::factory()->create();
        $request = $this->access->requestMembership($target, $this->organization);
        [$invitation, $token] = $this->inviteStaff($target, MembershipRole::Manager);
        $this->access->acceptInvitation($target, $token);
        $this->assertSame(MembershipRole::Manager, $target->memberships()->first()->role);
        $this->assertSame(JoinRequestStatus::Approved, $request->fresh()->status);
    }

    public function test_inviter_must_still_have_authority_at_acceptance(): void
    {
        $target = User::factory()->create();
        $invitation = $this->access->invite($this->manager, $target->email, $this->organization, MembershipRole::Staff);
        $token = basename(parse_url(Mail::sent(PortalInvitation::class)->last()->acceptUrl, PHP_URL_PATH));
        $this->manager->memberships()->delete();
        try {
            $this->access->acceptInvitation($target, $token);
            $this->fail('Removed manager invitation accepted');
        } catch (AuthorizationException) {
            $this->assertNull($invitation->fresh()->accepted_at);
        }
        $this->assertSame(0, $target->memberships()->count());
    }

    public function test_only_global_admin_can_invite_internal_roles(): void
    {
        $this->expectException(AuthorizationException::class);
        $this->access->invite($this->caseworker, 'internal@example.test', internalRole: InternalRole::Caseworker);
    }

    public function test_internal_role_is_granted_only_after_acceptance(): void
    {
        $target = User::factory()->create();
        $invitation = $this->access->invite($this->admin, $target->email, internalRole: InternalRole::Caseworker);
        $this->assertNull($target->fresh()->internal_role);
        $token = basename(parse_url(Mail::sent(PortalInvitation::class)->last()->acceptUrl, PHP_URL_PATH));
        $this->access->acceptInvitation($target, $token);
        $this->assertSame(InternalRole::Caseworker, $target->fresh()->internal_role);
    }

    public function test_old_internal_invitation_does_not_downgrade_global_admin(): void
    {
        $invitation = $this->access->invite($this->admin, $this->admin->email, internalRole: InternalRole::Caseworker);
        $token = basename(parse_url(Mail::sent(PortalInvitation::class)->last()->acceptUrl, PHP_URL_PATH));
        $this->access->acceptInvitation($this->admin, $token);
        $this->assertSame(InternalRole::GlobalAdmin, $this->admin->fresh()->internal_role);
    }

    public function test_last_active_global_admin_cannot_be_suspended_or_demoted(): void
    {
        foreach ([[InternalRole::GlobalAdmin, false], [InternalRole::Caseworker, true], [null, true]] as [$role, $active]) {
            try {
                $this->access->updateAccount($this->admin, $this->admin, $role, $active);
                $this->fail('Last admin removed');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('internal_role', $exception->errors());
            }
        }
        $this->assertTrue($this->admin->fresh()->isGlobalAdmin());
    }

    public function test_an_admin_can_be_removed_when_another_active_admin_remains(): void
    {
        $second = User::factory()->globalAdmin()->create();
        $this->access->updateAccount($this->admin, $second, null, false);
        $this->assertFalse($second->fresh()->is_active);
        $this->assertNull($second->fresh()->internal_role);
    }

    public function test_new_internal_roles_cannot_be_assigned_directly(): void
    {
        $this->expectException(ValidationException::class);
        $this->access->updateAccount($this->admin, $this->staff, InternalRole::Caseworker, true);
    }

    public function test_suspended_actor_cannot_use_stale_admin_rights(): void
    {
        $this->admin->forceFill(['is_active' => false])->save();
        $this->expectException(HttpException::class);
        $this->access->createOrganization($this->admin, 'Unzulässig');
    }

    public function test_local_seeder_grants_admin_without_replacing_existing_password(): void
    {
        $this->app->instance('env', 'local');
        config(['development.admin_email' => $this->staff->email, 'development.admin_password' => 'New-local-password-123']);
        $hash = $this->staff->password;
        $this->seed(LocalDevelopmentSeeder::class);
        $this->assertSame($hash, $this->staff->fresh()->password);
        $this->assertTrue($this->staff->fresh()->isGlobalAdmin());
        $this->assertNull($this->manager->fresh()->internal_role);
    }

    public function test_bootstrap_command_does_not_create_another_admin(): void
    {
        $this->artisan('admin:create')->expectsOutput('Ein aktiver Globaladmin existiert bereits. Weitere interne Rollen bitte per Einladung vergeben.')->assertFailed();
    }

    public function test_bootstrap_command_creates_first_admin_with_hashed_password(): void
    {
        $this->admin->forceFill(['internal_role' => null])->save();
        $this->artisan('admin:create')->expectsQuestion('E-Mail-Adresse', 'FIRST@example.test')
            ->expectsQuestion('Name', 'Erster Admin')
            ->expectsQuestion('Passwort (mindestens 16 Zeichen)', 'Long-Bootstrap-Password!')
            ->expectsQuestion('Passwort wiederholen', 'Long-Bootstrap-Password!')
            ->assertSuccessful();
        $admin = User::query()->where('email', 'first@example.test')->firstOrFail();
        $this->assertTrue($admin->isGlobalAdmin());
        $this->assertTrue(Hash::check('Long-Bootstrap-Password!', $admin->password));
    }
}
