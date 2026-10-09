<?php

namespace Tests\Feature;

use App\Enums\InternalRole;
use App\Enums\JoinRequestStatus;
use App\Enums\MembershipRole;
use App\Filament\Auth\Register;
use App\Filament\Pages\Accounts;
use App\Filament\Pages\Invitations;
use App\Filament\Pages\Members;
use App\Filament\Pages\OrganizationHome;
use App\Filament\Pages\Organizations;
use App\Filament\Pages\Requests;
use App\Livewire\AccessPage;
use App\Mail\PortalInvitation;
use App\Models\Invitation;
use App\Models\JoinRequest;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Services\PortalAccess;
use Filament\Actions\Testing\TestAction;
use Filament\Auth\Notifications\VerifyEmail;
use Filament\Auth\Pages\Login;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PortalInterfaceTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Organization $other;

    private User $manager;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->organization = Organization::factory()->create(['name' => 'Einrichtung A']);
        $this->other = Organization::factory()->create(['name' => 'Einrichtung B']);
        $this->manager = User::factory()->create();
        $this->staff = User::factory()->create();
        OrganizationMembership::factory()->manager()->create(['organization_id' => $this->organization->id, 'user_id' => $this->manager->id]);
        OrganizationMembership::factory()->create(['organization_id' => $this->organization->id, 'user_id' => $this->staff->id]);
    }

    private function panel(User $user, string $id = 'portal', ?Organization $organization = null): void
    {
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel($id));
        Filament::bootCurrentPanel();
        Filament::setTenant($id === 'portal' ? ($organization ?? $this->organization) : null);
    }

    public function test_public_registration_grants_no_internal_or_organization_rights(): void
    {
        Notification::fake();
        Filament::setCurrentPanel(Filament::getPanel('portal'));
        Filament::bootCurrentPanel();
        Livewire::test(Register::class)->fillForm([
            'name' => 'Neue Fachkraft', 'email' => 'NEW@example.test', 'password' => 'Safe-Registration-Password!',
            'passwordConfirmation' => 'Safe-Registration-Password!',
        ])->set('data.internal_role', 'global_admin')->set('data.is_active', false)->set('data.email_verified_at', now()->toDateTimeString())
            ->call('register')->assertHasNoFormErrors()->assertRedirect(route('access'));
        $user = User::query()->where('email', 'new@example.test')->firstOrFail();
        $this->assertNull($user->internal_role);
        $this->assertNull($user->email_verified_at);
        $this->assertTrue($user->is_active);
        $this->assertSame(0, $user->memberships()->count());
        Notification::assertSentTo($user, VerifyEmail::class);
    }

    public function test_registration_rejects_duplicate_email_case_variants(): void
    {
        Notification::fake();
        Filament::setCurrentPanel(Filament::getPanel('portal'));
        Filament::bootCurrentPanel();
        Livewire::test(Register::class)->fillForm([
            'name' => 'Duplicate', 'email' => strtoupper($this->staff->email),
            'password' => 'Safe-Registration-Password!', 'passwordConfirmation' => 'Safe-Registration-Password!',
        ])->call('register')->assertHasFormErrors(['email' => 'unique']);
    }

    public function test_guests_and_unverified_users_cannot_access_onboarding(): void
    {
        $this->get('/zugang')->assertRedirect('/portal/login');
        $this->actingAs(User::factory()->unverified()->create())->get('/zugang')->assertRedirect('/email-bestaetigen');
        $this->get('/email-bestaetigen')->assertRedirect('/portal/email-verification/prompt');
    }

    public function test_signed_verification_link_confirms_the_email(): void
    {
        $user = User::factory()->unverified()->create();
        Filament::setCurrentPanel(Filament::getPanel('portal'));
        $this->actingAs($user)->get(Filament::getVerifyEmailUrl($user))->assertRedirect();
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
    }

    public function test_account_without_membership_sees_only_access_page(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get('/zugang')->assertOk()->assertSee('Du bist noch keiner Einrichtung zugeordnet.');
        $this->get('/portal')->assertRedirect('/zugang');
        $this->get('/admin')->assertForbidden();
        $this->get('/portal/einrichtung/'.$this->organization->id)->assertNotFound();
    }

    public function test_directory_exposes_names_without_members_or_world_data(): void
    {
        $outsider = User::factory()->create();
        $this->actingAs($outsider);
        Livewire::test(AccessPage::class)->set('search', 'Einrichtung')
            ->assertSee('Einrichtung A')->assertSee('Einrichtung B')
            ->assertDontSee($this->manager->email)->assertDontSee($this->staff->email)
            ->call('requestJoin', $this->organization->id)->assertHasNoErrors()
            ->assertSee('Dein Beitrittsantrag wurde eingereicht.');
        $this->assertSame(0, $outsider->memberships()->count());
    }

    public function test_access_page_cannot_cancel_another_users_request(): void
    {
        $request = JoinRequest::factory()->create(['organization_id' => $this->organization->id]);
        $this->actingAs($this->staff);
        Livewire::test(AccessPage::class)->call('cancelJoin', $request->id)->assertForbidden();
        $this->assertSame(JoinRequestStatus::Pending, $request->fresh()->status);
    }

    public static function environments(): array
    {
        return ['local' => ['local'], 'production' => ['production']];
    }

    #[DataProvider('environments')]
    public function test_panel_and_tenant_authorization_is_environment_independent(string $environment): void
    {
        $this->app->instance('env', $environment);
        config(['app.env' => $environment]);
        $this->actingAs($this->staff)->get('/admin')->assertForbidden();
        $this->get('/portal/einrichtung/'.$this->organization->id)->assertOk();
        $this->get('/portal/einrichtung/'.$this->other->id)->assertNotFound();
        $this->get('/portal/einrichtung/'.$this->organization->id.'/mitgliedschaften')->assertForbidden();
    }

    public function test_manager_sees_only_current_organization_members(): void
    {
        $otherMember = OrganizationMembership::factory()->create(['organization_id' => $this->other->id]);
        $this->panel($this->manager);
        Livewire::test(Members::class)->assertCanSeeTableRecords($this->organization->memberships()->get())
            ->assertCanNotSeeTableRecords([$otherMember])->assertDontSee($otherMember->user->email);
    }

    public function test_multi_organization_memberships_allow_switching_without_combining_data(): void
    {
        OrganizationMembership::factory()->manager()->create(['organization_id' => $this->other->id, 'user_id' => $this->manager->id]);
        $this->panel($this->manager, organization: $this->other);
        $this->assertSame(2, $this->manager->getTenants(Filament::getPanel('portal'))->count());
        Livewire::test(Members::class)->assertCanSeeTableRecords($this->other->memberships()->get())
            ->assertCanNotSeeTableRecords($this->organization->memberships()->get());
    }

    public function test_manager_can_assign_an_existing_verified_staff_account(): void
    {
        $target = User::factory()->create();
        $this->panel($this->manager);
        Livewire::test(Members::class)->callAction('assign', ['email' => $target->email, 'membership_role' => 'staff'])->assertHasNoActionErrors();
        $this->assertDatabaseHas('organization_memberships', ['organization_id' => $this->organization->id, 'user_id' => $target->id, 'role' => 'staff']);
    }

    public function test_tampered_role_cannot_promote_from_external_panel(): void
    {
        $target = User::factory()->create();
        $this->panel($this->manager);
        Livewire::test(Members::class)->callAction('assign', ['email' => $target->email, 'membership_role' => 'manager'])
            ->assertHasActionErrors(['membership_role']);
        $this->assertSame(0, $target->memberships()->count());
    }

    public function test_external_assignment_cannot_be_redirected_by_a_forged_organization_field(): void
    {
        $target = User::factory()->create();
        $this->panel($this->manager);
        Livewire::test(Members::class)->callAction('assign', [
            'email' => $target->email,
            'membership_role' => 'staff',
            'organization_id' => $this->other->id,
        ])->assertHasNoActionErrors();
        $this->assertSame([$this->organization->id], $target->memberships()->pluck('organization_id')->all());
    }

    public function test_forged_table_action_cannot_remove_a_foreign_member(): void
    {
        $foreign = OrganizationMembership::factory()->create(['organization_id' => $this->other->id]);
        $this->panel($this->manager);
        Livewire::test(Members::class)->call('mountAction', 'remove', [], ['table' => true, 'recordKey' => $foreign->id])->call('callMountedAction');
        $this->assertDatabaseHas('organization_memberships', ['id' => $foreign->id]);
    }

    public function test_open_livewire_page_loses_access_after_membership_removal(): void
    {
        $this->panel($this->manager);
        $page = Livewire::test(Members::class);
        $this->manager->memberships()->delete();
        $page->call('$refresh')->assertForbidden();
    }

    public function test_open_livewire_page_loses_management_after_demotion(): void
    {
        $this->panel($this->manager);
        $page = Livewire::test(Members::class);
        $this->manager->memberships()->update(['role' => 'staff']);
        $page->call('$refresh')->assertForbidden();
        $this->assertTrue($this->manager->canAccessTenant($this->organization));
    }

    public function test_open_access_page_is_blocked_when_account_is_suspended(): void
    {
        $this->actingAs($this->staff);
        $page = Livewire::test(AccessPage::class);
        $this->staff->forceFill(['is_active' => false])->save();
        $page->call('requestJoin', $this->other->id)->assertForbidden();
        $this->assertSame(0, JoinRequest::query()->count());
    }

    public function test_open_internal_page_is_blocked_after_internal_role_removal(): void
    {
        $admin = User::factory()->globalAdmin()->create();
        $this->panel($admin, 'admin');
        $page = Livewire::test(Accounts::class);
        $admin->forceFill(['internal_role' => null])->save();
        $page->call('$refresh')->assertForbidden();
    }

    public function test_internal_pages_show_german_labels_and_respect_caseworker_limits(): void
    {
        $caseworker = User::factory()->caseworker()->create();
        $this->panel($caseworker, 'admin');
        Livewire::test(Organizations::class)->assertSee('Einrichtungen')->assertActionHidden('create');
        Livewire::test(Members::class)->assertSee('Mitgliedschaften')->assertCanSeeTableRecords($this->organization->memberships()->get());
        Livewire::test(Requests::class)->assertSee('Beitrittsanträge');
        Livewire::test(Invitations::class)->assertSee('Einladungen')->assertActionHidden('inviteInternal');
        $this->get('/admin/konten')->assertForbidden();
    }

    public function test_caseworker_can_assign_organization_management_via_livewire(): void
    {
        $target = User::factory()->create();
        $this->panel(User::factory()->caseworker()->create(), 'admin');
        Livewire::test(Members::class)->callAction('assign', ['organization_id' => $this->other->id, 'email' => $target->email, 'membership_role' => 'manager'])
            ->assertHasNoActionErrors();
        $this->assertDatabaseHas('organization_memberships', ['organization_id' => $this->other->id, 'user_id' => $target->id, 'role' => 'manager']);
    }

    public function test_requests_and_invitations_are_tenant_scoped(): void
    {
        $ownRequest = JoinRequest::factory()->create(['organization_id' => $this->organization->id]);
        $foreignRequest = JoinRequest::factory()->create(['organization_id' => $this->other->id]);
        $ownInvitation = Invitation::factory()->create(['organization_id' => $this->organization->id]);
        $foreignInvitation = Invitation::factory()->create(['organization_id' => $this->other->id]);
        $this->panel($this->manager);
        Livewire::test(Requests::class)->assertCanSeeTableRecords([$ownRequest])->assertCanNotSeeTableRecords([$foreignRequest])
            ->callAction(TestAction::make('approve')->table($ownRequest))->assertHasNoActionErrors();
        Livewire::test(Invitations::class)->assertCanSeeTableRecords([$ownInvitation])->assertCanNotSeeTableRecords([$foreignInvitation]);
        $this->assertSame(JoinRequestStatus::Approved, $ownRequest->fresh()->status);
        $this->assertSame(JoinRequestStatus::Pending, $foreignRequest->fresh()->status);
    }

    public function test_caseworker_cannot_read_internal_invitations(): void
    {
        $admin = User::factory()->globalAdmin()->create();
        $internal = Invitation::factory()->create(['organization_id' => null, 'membership_role' => null, 'internal_role' => InternalRole::Caseworker, 'invited_by' => $admin->id]);
        $this->panel(User::factory()->caseworker()->create(), 'admin');
        Livewire::test(Invitations::class)->assertCanNotSeeTableRecords([$internal])->assertDontSee($internal->email);
    }

    public function test_manager_can_rename_only_current_organization(): void
    {
        $this->panel($this->manager);
        Livewire::test(OrganizationHome::class)->callAction('rename', ['name' => 'Einrichtung umbenannt'])->assertHasNoActionErrors();
        $this->assertSame('Einrichtung umbenannt', $this->organization->fresh()->name);
        $this->assertSame('Einrichtung B', $this->other->fresh()->name);
    }

    public function test_admin_can_create_organizations_and_review_accounts(): void
    {
        $this->panel(User::factory()->globalAdmin()->create(), 'admin');
        Livewire::test(Organizations::class)->callAction('create', ['name' => 'Neue Einrichtung'])->assertHasNoActionErrors();
        Livewire::test(Accounts::class)->assertSee('Konten')->assertCanSeeTableRecords([$this->staff, $this->manager]);
        $this->assertDatabaseHas('organizations', ['name' => 'Neue Einrichtung']);
    }

    public function test_opening_invitation_does_not_accept_it_and_survives_login(): void
    {
        $target = User::factory()->create(['password' => 'Portal-Test-Password!']);
        $invitation = app(PortalAccess::class)->invite(User::factory()->globalAdmin()->create(), $target->email, $this->organization, MembershipRole::Staff);
        $url = Mail::sent(PortalInvitation::class)->last()->acceptUrl;
        $this->get($url)->assertRedirect('/zugang');
        $this->assertNotNull(session('invitation_hash'));
        $this->assertNull(session('invitation_token'));
        $this->assertNull($invitation->fresh()->accepted_at);
        Filament::setCurrentPanel(Filament::getPanel('portal'));
        Filament::bootCurrentPanel();
        Livewire::test(Login::class)->fillForm(['email' => $target->email, 'password' => 'Portal-Test-Password!'])
            ->call('authenticate')->assertHasNoFormErrors();
        Livewire::test(AccessPage::class)->assertSee('Persönliche Einladung')->call('acceptInvitation')->assertHasNoErrors();
        $this->assertNotNull($invitation->fresh()->accepted_at);
        $this->assertTrue($target->canAccessTenant($this->organization));
    }

    public function test_logout_ends_the_portal_session(): void
    {
        $this->actingAs($this->staff)->post('/abmelden')->assertRedirect('/portal/login');
        $this->assertGuest();
    }

    public static function previewRoles(): array
    {
        return ['admin' => ['admin'], 'caseworker' => ['caseworker'], 'manager' => ['manager'], 'staff' => ['staff'], 'unassigned' => ['unassigned']];
    }

    #[DataProvider('previewRoles')]
    public function test_role_pages_render_with_their_expected_navigation(string $role): void
    {
        $user = match ($role) {
            'admin' => User::factory()->globalAdmin()->create(),
            'caseworker' => User::factory()->caseworker()->create(),
            'manager' => $this->manager,
            'staff' => $this->staff,
            default => User::factory()->create(),
        };
        $url = match ($role) {
            'admin' => '/admin/konten',
            'caseworker' => '/admin/mitgliedschaften',
            'manager' => '/portal/einrichtung/'.$this->organization->id.'/mitgliedschaften',
            'staff' => '/portal/einrichtung/'.$this->organization->id,
            default => '/zugang',
        };
        $response = $this->actingAs($user)->get($url)->assertOk();
        if ($role === 'staff') {
            $response->assertDontSee('href="'.route('filament.portal.pages.mitgliedschaften', ['tenant' => $this->organization->id]).'"', false);
        }
        if ($role === 'caseworker') {
            $response->assertDontSee('href="'.route('filament.admin.pages.konten').'"', false);
        }
        if (env('PORTAL_CAPTURE_PREVIEWS', false)) {
            $directory = storage_path('app/qa');
            if (! is_dir($directory)) {
                mkdir($directory, 0755, true);
            }
            $html = str_replace('http://localhost/', 'http://127.0.0.1:8000/', $response->getContent());
            $html = str_replace('<head>', '<head><base href="http://127.0.0.1:8000/">', $html);
            file_put_contents($directory.'/'.$role.'.html', $html);
        }
    }
}
