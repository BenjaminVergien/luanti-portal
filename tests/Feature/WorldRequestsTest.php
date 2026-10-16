<?php

namespace Tests\Feature;

use App\Enums\WorldRequestStatus;
use App\Models\LuantiWorldTemplate;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Models\WorldRequest;
use App\Services\WorldRequests;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class WorldRequestsTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $applicant;

    private User $manager;

    private User $member;

    private LuantiWorldTemplate $world;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now('Europe/Berlin')->setDate(2026, 10, 11)->startOfDay());
        $this->organization = Organization::factory()->create();
        $this->applicant = User::factory()->create();
        $this->manager = User::factory()->create();
        $this->member = User::factory()->create();
        foreach ([$this->applicant, $this->member] as $user) {
            OrganizationMembership::factory()->create(['organization_id' => $this->organization->id, 'user_id' => $user->id]);
        }
        OrganizationMembership::factory()->manager()->create(['organization_id' => $this->organization->id, 'user_id' => $this->manager->id]);
        $this->world = LuantiWorldTemplate::factory()->create();
        Http::preventStrayRequests();
    }

    /** @return array{name: string, luanti_world_template_id: int, start_date: string, end_date: string, participant_count: int} */
    private function data(): array
    {
        return ['name' => '  Jugendtreff  ', 'luanti_world_template_id' => $this->world->id,
            'start_date' => '2026-10-11', 'end_date' => '2026-10-11', 'participant_count' => 15];
    }

    private function request(): WorldRequest
    {
        return app(WorldRequests::class)->create($this->applicant, $this->organization, $this->data());
    }

    public function test_members_submit_same_day_overlapping_requests_without_institution_verification_or_network_calls(): void
    {
        $first = $this->request();
        $second = app(WorldRequests::class)->create($this->member, $this->organization, [
            ...$this->data(), 'status' => 'approved', 'user_id' => $this->manager->id,
            'organization_id' => Organization::factory()->create()->id, 'decided_by' => $this->manager->id, 'revision' => 999,
        ]);
        $this->assertNull($this->organization->verified_at);
        $this->assertSame('Jugendtreff', $first->name);
        $this->assertSame(WorldRequestStatus::Pending, $second->status);
        $this->assertSame($this->member->id, $second->user_id);
        $this->assertSame($this->organization->id, $second->organization_id);
        $this->assertSame(1, $second->revision);
        $this->assertNull($second->decided_by);
        $this->assertCount(2, $this->organization->worldRequests);
        $this->assertCount(1, $this->applicant->worldRequests);
        $this->assertCount(2, $this->world->worldRequests);
        Http::assertNothingSent();
    }

    public static function invalidData(): array
    {
        return [
            'blank name' => ['name', '   '], 'long name' => ['name', str_repeat('a', 256)],
            'invalid world' => ['luanti_world_template_id', 999999], 'world array' => ['luanti_world_template_id', []],
            'past start' => ['start_date', '2026-10-10'], 'invalid date' => ['start_date', '2026-02-30'],
            'date with time' => ['start_date', '2026-10-11 12:00:00'],
            'end before start' => ['end_date', '2026-10-10'], 'missing end' => ['end_date', null],
            'zero participants' => ['participant_count', 0], 'negative participants' => ['participant_count', -1],
            'fractional participants' => ['participant_count', 2.5], 'missing participants' => ['participant_count', null],
            'count over storage limit' => ['participant_count', 4294967296],
        ];
    }

    #[DataProvider('invalidData')]
    public function test_invalid_requests_are_rejected(string $field, mixed $value): void
    {
        try {
            app(WorldRequests::class)->create($this->applicant, $this->organization, [...$this->data(), $field => $value]);
            $this->fail('Expected validation failure.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
        }
        $this->assertDatabaseCount('world_requests', 0);
    }

    public static function editorChanges(): array
    {
        $cases = [];
        foreach (['applicant', 'manager', 'admin', 'caseworker'] as $editor) {
            foreach (['start_date', 'end_date', 'luanti_world_template_id', 'name', 'participant_count', 'unchanged'] as $field) {
                $cases[$editor.' '.$field] = [$editor, $field];
            }
        }

        return $cases;
    }

    #[DataProvider('editorChanges')]
    public function test_approved_edits_reapprove_only_date_changes_and_template_switch_is_self_service(string $editor, string $field): void
    {
        $service = app(WorldRequests::class);
        $request = $this->request();
        $reviewer = User::factory()->caseworker()->create();
        $service->decide($reviewer, $request, true, 1);
        $actor = match ($editor) {
            'applicant' => $this->applicant, 'manager' => $this->manager,
            'admin' => User::factory()->globalAdmin()->create(), 'caseworker' => $reviewer,
        };
        $data = [...$this->data(), 'end_date' => '2026-10-15'];
        $service->update($actor, $request, $data, 2);
        $service->decide($reviewer, $request, true, 3);
        $data = match ($field) {
            'start_date' => [...$data, 'start_date' => '2026-10-12'],
            'end_date' => [...$data, 'end_date' => '2026-10-16'],
            'luanti_world_template_id' => [...$data, 'luanti_world_template_id' => LuantiWorldTemplate::factory()->create(['luanti_server_id' => $request->world->luanti_server_id])->id],
            'name' => [...$data, 'name' => 'Neuer Name'],
            'participant_count' => [...$data, 'participant_count' => 20],
            default => $data,
        };
        if ($field === 'luanti_world_template_id') {
            $service->changeTemplate($actor, $request, $data['luanti_world_template_id'], 4);
        } else {
            $service->update($actor, $request, [...$data, 'status' => 'rejected', 'user_id' => $actor->id], 4);
        }
        $fresh = $request->fresh();
        $reapproval = in_array($field, ['start_date', 'end_date'], true);
        $this->assertSame($reapproval ? WorldRequestStatus::Pending : WorldRequestStatus::Approved, $fresh->status);
        $this->assertSame($reapproval ? null : $reviewer->id, $fresh->decided_by);
        $this->assertSame($reapproval, $fresh->decided_at === null);
        $this->assertSame($this->applicant->id, $fresh->user_id);
        $this->assertSame(5, $fresh->revision);
    }

    public function test_historical_dates_allow_count_edits_but_replacement_dates_must_start_today_or_later(): void
    {
        $service = app(WorldRequests::class);
        $request = $this->request();
        $service->decide(User::factory()->globalAdmin()->create(), $request, true, 1);
        $this->travelTo(now('Europe/Berlin')->addDays(3));
        $service->update($this->applicant, $request, [...$this->data(), 'participant_count' => 20], 2);
        $this->assertSame(WorldRequestStatus::Approved, $request->fresh()->status);
        $this->expectException(ValidationException::class);
        $service->update($this->applicant, $request, [...$this->data(), 'end_date' => '2026-10-20'], 3);
    }

    public function test_calendar_validation_uses_berlin_day_even_when_application_timezone_is_utc(): void
    {
        config(['app.timezone' => 'UTC']);
        $this->travelTo(Carbon::parse('2026-10-11 22:30:00', 'UTC'));
        $this->expectException(ValidationException::class);
        $this->request();
    }

    public function test_all_members_view_only_their_institution_but_only_applicants_managers_and_internal_staff_edit(): void
    {
        $request = $this->request();
        $other = WorldRequest::factory()->create();
        $this->assertTrue(Gate::forUser($this->member)->allows('view', $request));
        $this->assertFalse(Gate::forUser($this->member)->allows('update', $request));
        $this->assertFalse(Gate::forUser($this->manager)->allows('decide', $request));
        $this->assertFalse(Gate::forUser($this->member)->allows('view', $other));
        $this->assertSame([$request->id], app(WorldRequests::class)->query($this->member, $this->organization)->pluck('id')->all());
        foreach ([User::factory()->globalAdmin()->create(), User::factory()->caseworker()->create()] as $internal) {
            $this->assertCount(2, app(WorldRequests::class)->query($internal)->get());
            $this->assertTrue(Gate::forUser($internal)->allows('update', $request));
            $this->assertTrue(Gate::forUser($internal)->allows('decide', $request));
        }
        $this->expectException(AuthorizationException::class);
        app(WorldRequests::class)->create($this->applicant, $other->organization, $this->data());
    }

    public function test_ordinary_member_cannot_edit_another_applicants_request(): void
    {
        $request = $this->request();
        $this->expectException(AuthorizationException::class);
        app(WorldRequests::class)->update($this->member, $request, $this->data(), 1);
    }

    public function test_rejection_records_decision_and_prevents_edits_or_further_decisions(): void
    {
        $request = $this->request();
        $reviewer = User::factory()->caseworker()->create();
        app(WorldRequests::class)->decide($reviewer, $request, false, 1);
        $this->assertSame(WorldRequestStatus::Rejected, $request->fresh()->status);
        $this->assertSame($reviewer->id, $request->fresh()->decided_by);
        $this->assertNotNull($request->fresh()->decided_at);
        $this->assertFalse(Gate::forUser($reviewer)->allows('decide', $request));
        $this->assertFalse(Gate::forUser($reviewer)->allows('update', $request));
        $this->assertFalse(Gate::forUser($this->manager)->allows('update', $request));
        $this->expectException(AuthorizationException::class);
        app(WorldRequests::class)->update($this->applicant, $request, $this->data(), 2);
    }

    public static function revokedAccess(): array
    {
        return ['inactive' => ['inactive'], 'unverified' => ['unverified'], 'membership removed' => ['membership']];
    }

    #[DataProvider('revokedAccess')]
    public function test_service_and_policy_recheck_access_after_revocation(string $change): void
    {
        $request = $this->request();
        if ($change === 'membership') {
            $this->applicant->memberships()->delete();
        } else {
            User::query()->whereKey($this->applicant->id)->update($change === 'inactive' ? ['is_active' => false] : ['email_verified_at' => null]);
        }
        $this->assertFalse(Gate::forUser($this->applicant)->allows('view', $request));
        try {
            app(WorldRequests::class)->update($this->applicant, $request, $this->data(), 1);
            $this->fail('Expected authorization failure.');
        } catch (AuthorizationException|HttpException $exception) {
            $this->assertSame(1, $request->fresh()->revision);
        }
    }

    public function test_stale_edits_cannot_overwrite_a_newer_approval(): void
    {
        $request = $this->request();
        app(WorldRequests::class)->decide(User::factory()->caseworker()->create(), $request, true, 1);
        try {
            app(WorldRequests::class)->update($this->applicant, $request, [...$this->data(), 'end_date' => '2026-10-20'], 1);
            $this->fail('Expected revision failure.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('revision', $exception->errors());
        }
        $this->assertSame(WorldRequestStatus::Approved, $request->fresh()->status);
        $this->assertSame('2026-10-11', $request->fresh()->end_date->toDateString());
    }

    public function test_stale_decisions_cannot_approve_a_newer_applicant_edit(): void
    {
        $request = $this->request();
        app(WorldRequests::class)->update($this->applicant, $request, [...$this->data(), 'participant_count' => 20], 1);
        $this->expectException(ValidationException::class);
        app(WorldRequests::class)->decide(User::factory()->caseworker()->create(), $request, true, 1);
    }

    public function test_retired_worlds_remain_visible_and_allow_corrections_but_cannot_be_approved(): void
    {
        $request = $this->request();
        $this->world->update(['is_selectable' => false]);
        $service = app(WorldRequests::class);
        $this->assertArrayNotHasKey($this->world->id, $service->worldOptions($this->applicant, $this->organization));
        $this->assertArrayHasKey($this->world->id, $service->worldOptions($this->applicant, $this->organization, $request));
        $service->update($this->manager, $request, [...$this->data(), 'participant_count' => 20], 1);
        $this->assertSame(20, $request->fresh()->participant_count);
        $this->expectException(ValidationException::class);
        $service->decide(User::factory()->caseworker()->create(), $request, true, 2);
    }

    public function test_retired_world_cannot_be_selected_on_new_request(): void
    {
        $this->world->update(['is_selectable' => false]);
        $this->expectException(ValidationException::class);
        $this->request();
    }

    public function test_update_cannot_switch_to_a_retired_world(): void
    {
        $request = $this->request();
        $retired = LuantiWorldTemplate::factory()->retired()->create();
        $this->expectException(ValidationException::class);
        app(WorldRequests::class)->update($this->applicant, $request, [...$this->data(), 'luanti_world_template_id' => $retired->id], 1);
    }
}
