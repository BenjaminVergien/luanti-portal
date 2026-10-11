<?php

namespace Tests\Feature;

use App\Enums\WorldRequestStatus;
use App\Filament\Pages\WorldRequests;
use App\Models\LuantiWorld;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Models\WorldRequest;
use App\Services\WorldRequests as WorldRequestService;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class WorldRequestsInterfaceTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $applicant;

    private User $manager;

    private User $member;

    private LuantiWorld $world;

    protected function setUp(): void
    {
        parent::setUp();
        $this->organization = Organization::factory()->create();
        $this->applicant = User::factory()->create();
        $this->manager = User::factory()->create();
        $this->member = User::factory()->create();
        foreach ([$this->applicant, $this->member] as $user) {
            OrganizationMembership::factory()->create(['organization_id' => $this->organization->id, 'user_id' => $user->id]);
        }
        OrganizationMembership::factory()->manager()->create(['organization_id' => $this->organization->id, 'user_id' => $this->manager->id]);
        $this->world = LuantiWorld::factory()->create();
        Http::preventStrayRequests();
    }

    private function panel(User $user, string $id = 'portal'): void
    {
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel($id));
        Filament::bootCurrentPanel();
        Filament::setTenant($id === 'portal' ? $this->organization : null);
    }

    /** @return array{name: string, luanti_world_id: int, start_date: string, end_date: string, participant_count: int} */
    private function data(): array
    {
        return ['name' => 'Jugendtreff', 'luanti_world_id' => $this->world->id,
            'start_date' => now('Europe/Berlin')->toDateString(), 'end_date' => now('Europe/Berlin')->addDays(7)->toDateString(),
            'participant_count' => 12];
    }

    private function request(): WorldRequest
    {
        return app(WorldRequestService::class)->create($this->applicant, $this->organization, $this->data());
    }

    public function test_members_open_request_page_and_homepage_link_without_manager_permissions(): void
    {
        $this->actingAs($this->member)->get('/portal/einrichtung/'.$this->organization->id.'/weltantraege')
            ->assertOk()->assertSee('Welt beantragen');
        $this->get('/portal/einrichtung/'.$this->organization->id)->assertOk()
            ->assertSee('Welt beantragen und Weltanträge ansehen')
            ->assertSee('/portal/einrichtung/'.$this->organization->id.'/weltantraege');
    }

    public function test_guests_outsiders_unverified_and_inactive_users_cannot_open_institution_requests(): void
    {
        $url = '/portal/einrichtung/'.$this->organization->id.'/weltantraege';
        $this->get($url)->assertRedirect('/portal/login');
        $this->actingAs(User::factory()->create())->get($url)->assertNotFound();
        $this->actingAs($this->member);
        $this->member->forceFill(['is_active' => false])->save();
        $this->get($url)->assertForbidden();
        $this->member->forceFill(['is_active' => true, 'email_verified_at' => null])->save();
        $this->get($url)->assertNotFound();
    }

    public function test_member_creates_request_without_exposing_server_credentials_or_accepting_forged_fields(): void
    {
        $this->world->server->update(['api_token' => 'protected-token', 'api_base_url' => 'https://private.example.test']);
        $this->panel($this->member);
        Livewire::test(WorldRequests::class)->callAction('create', [
            ...$this->data(), 'status' => 'approved', 'user_id' => $this->applicant->id, 'organization_id' => 999999,
        ])->assertHasNoActionErrors()->assertNotified('Weltantrag eingereicht')
            ->assertDontSee('protected-token')->assertDontSee('https://private.example.test');
        $request = WorldRequest::query()->sole();
        $this->assertSame($this->member->id, $request->user_id);
        $this->assertSame($this->organization->id, $request->organization_id);
        $this->assertSame(WorldRequestStatus::Pending, $request->status);
        Http::assertNothingSent();
    }

    public function test_form_rejects_missing_fields_and_invalid_dates_and_count(): void
    {
        $this->panel($this->applicant);
        $page = Livewire::test(WorldRequests::class)->callAction('create')->assertHasActionErrors([
            'name' => 'required', 'luanti_world_id' => 'required', 'start_date' => 'required',
            'end_date' => 'required', 'participant_count' => 'required',
        ]);
        $page->fillForm([...$this->data(), 'end_date' => now('Europe/Berlin')->subDay()->toDateString(), 'participant_count' => 0])
            ->callMountedAction()->assertHasActionErrors(['end_date', 'participant_count']);
        $this->assertDatabaseCount('world_requests', 0);
    }

    public function test_tables_are_scoped_searchable_and_filterable_with_correct_member_actions(): void
    {
        $pending = $this->request();
        $approved = WorldRequest::factory()->approved()->create(['organization_id' => $this->organization->id, 'name' => 'Ferienwelt']);
        $other = WorldRequest::factory()->create();
        $this->panel($this->member);
        $page = Livewire::test(WorldRequests::class)->assertCanSeeTableRecords([$pending, $approved])
            ->assertCanNotSeeTableRecords([$other])
            ->assertActionHidden(TestAction::make('edit')->table($pending))
            ->assertActionHidden(TestAction::make('approve')->table($pending))
            ->assertActionHidden(TestAction::make('reject')->table($pending));
        $page->searchTable('Ferienwelt')->assertCanSeeTableRecords([$approved])->assertCanNotSeeTableRecords([$pending]);
        $page->searchTable('')->filterTable('status', 'pending')->assertCanSeeTableRecords([$pending])->assertCanNotSeeTableRecords([$approved]);
    }

    public static function editors(): array
    {
        return ['applicant' => ['applicant'], 'manager' => ['manager'], 'admin' => ['admin'], 'caseworker' => ['caseworker']];
    }

    #[DataProvider('editors')]
    public function test_every_authorized_editor_can_change_name_and_dates_with_reapproval(string $role): void
    {
        $request = $this->request();
        app(WorldRequestService::class)->decide(User::factory()->caseworker()->create(), $request, true, 1);
        $actor = match ($role) {
            'applicant' => $this->applicant, 'manager' => $this->manager,
            'admin' => User::factory()->globalAdmin()->create(), 'caseworker' => User::factory()->caseworker()->create(),
        };
        $this->panel($actor, in_array($role, ['admin', 'caseworker'], true) ? 'admin' : 'portal');
        Livewire::test(WorldRequests::class)->callAction(TestAction::make('edit')->table($request), ['name' => 'Neuer Name'])
            ->assertHasNoActionErrors()->assertNotified('Weltantrag gespeichert');
        $this->assertSame(WorldRequestStatus::Approved, $request->fresh()->status);
        Livewire::test(WorldRequests::class)->callAction(TestAction::make('edit')->table($request), [
            'end_date' => now('Europe/Berlin')->addDays(10)->toDateString(),
        ])->assertHasNoActionErrors();
        $this->assertSame(WorldRequestStatus::Pending, $request->fresh()->status);
        $this->assertNull($request->fresh()->decided_at);
    }

    public function test_internal_roles_review_all_institutions_and_record_decisions(): void
    {
        $first = $this->request();
        $second = WorldRequest::factory()->create();
        $reviewer = User::factory()->caseworker()->create();
        $this->panel($reviewer, 'admin');
        $this->get('/admin/weltantraege')->assertOk();
        Livewire::test(WorldRequests::class)->assertCanSeeTableRecords([$first, $second])
            ->assertActionHidden('create')->callAction(TestAction::make('approve')->table($first))
            ->assertHasNoActionErrors()->assertNotified('Weltantrag genehmigt');
        $this->assertSame(WorldRequestStatus::Approved, $first->fresh()->status);
        $this->assertSame($reviewer->id, $first->fresh()->decided_by);
        $this->panel(User::factory()->globalAdmin()->create(), 'admin');
        Livewire::test(WorldRequests::class)->callAction(TestAction::make('reject')->table($second))
            ->assertHasNoActionErrors()->assertNotified('Weltantrag abgelehnt')
            ->assertActionHidden(TestAction::make('edit')->table($second));
        $this->assertSame(WorldRequestStatus::Rejected, $second->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_stale_edit_form_preserves_new_staff_decision_and_shows_conflict(): void
    {
        $request = $this->request();
        $this->panel($this->applicant);
        $page = Livewire::test(WorldRequests::class)->mountAction(TestAction::make('edit')->table($request))
            ->assertActionDataSet(['revision' => 1]);
        app(WorldRequestService::class)->decide(User::factory()->caseworker()->create(), $request, true, 1);
        $page->fillForm(['end_date' => now('Europe/Berlin')->addDays(10)->toDateString()])
            ->callMountedAction()->assertHasActionErrors(['revision'])
            ->assertNotified('Weltantrag konnte nicht gespeichert werden');
        $this->assertSame(WorldRequestStatus::Approved, $request->fresh()->status);
        $this->assertSame(2, $request->fresh()->revision);
    }

    public function test_stale_approval_form_cannot_approve_a_changed_request(): void
    {
        $request = $this->request();
        $this->panel(User::factory()->caseworker()->create(), 'admin');
        $page = Livewire::test(WorldRequests::class)->mountAction(TestAction::make('approve')->table($request));
        app(WorldRequestService::class)->update($this->applicant, $request, [...$this->data(), 'participant_count' => 20], 1);
        $page->callMountedAction()->assertHasActionErrors(['revision']);
        $this->assertSame(WorldRequestStatus::Pending, $request->fresh()->status);
    }

    public function test_retired_world_remains_in_existing_form_but_not_new_request_options(): void
    {
        $request = $this->request();
        $this->world->update(['is_selectable' => false]);
        $this->panel($this->applicant);
        Livewire::test(WorldRequests::class)->mountAction(TestAction::make('edit')->table($request))
            ->assertActionDataSet(['luanti_world_id' => $this->world->id])->assertMountedActionModalSee('Nicht mehr beantragbar')
            ->fillForm(['participant_count' => 20])->callMountedAction()->assertHasNoActionErrors();
        Livewire::test(WorldRequests::class)->callAction('create', $this->data())->assertHasActionErrors(['luanti_world_id']);
    }

    public static function revocations(): array
    {
        $cases = [];
        foreach (['membership', 'inactive', 'unverified', 'internal_role'] as $change) {
            foreach (['refresh', 'save'] as $operation) {
                $cases[$change.' '.$operation] = [$change, $operation];
            }
        }

        return $cases;
    }

    #[DataProvider('revocations')]
    public function test_open_pages_recheck_permissions_on_refresh_and_mounted_actions(string $change, string $operation): void
    {
        $request = $this->request();
        $actor = $change === 'internal_role' ? User::factory()->caseworker()->create() : $this->applicant;
        $this->panel($actor, $change === 'internal_role' ? 'admin' : 'portal');
        $page = Livewire::test(WorldRequests::class);
        if ($operation === 'save') {
            $page->mountAction(TestAction::make('edit')->table($request))->fillForm(['name' => 'Unauthorized']);
        }
        if ($change === 'membership') {
            $actor->memberships()->delete();
        } else {
            $actor->forceFill(match ($change) {
                'inactive' => ['is_active' => false], 'unverified' => ['email_verified_at' => null], default => ['internal_role' => null],
            })->save();
        }
        $page->call($operation === 'refresh' ? '$refresh' : 'callMountedAction')->assertForbidden();
        $this->assertSame('Jugendtreff', $request->fresh()->name);
        $this->assertSame(1, $request->fresh()->revision);
    }

    public function test_forged_cross_institution_table_record_cannot_be_edited(): void
    {
        $other = WorldRequest::factory()->create();
        $this->panel($this->manager);
        Livewire::test(WorldRequests::class)->assertActionDoesNotExist(TestAction::make('edit')->table($other));
        $this->assertNotSame('Unauthorized', $other->fresh()->name);
    }
}
