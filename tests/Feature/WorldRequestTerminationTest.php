<?php

namespace Tests\Feature;

use App\Enums\WorldRequestStatus;
use App\Filament\Pages\WorldRequests;
use App\Jobs\CleanupLuantiDns;
use App\Jobs\TerminateWorldRequest;
use App\Models\LuantiAccountJob;
use App\Models\LuantiWorldTemplate;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Models\WorldRequest;
use App\Services\HetznerVirtualMachines;
use App\Services\LuantiAccounts;
use App\Services\LuantiGameInstances;
use App\Services\WorldRequestMailings;
use App\Services\WorldRequestProvisioning;
use App\Services\WorldRequestTermination;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class WorldRequestTerminationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::preventStrayRequests();
        config(['services.hetzner.token' => 'project-secret']);
    }

    private function approvedRequest(): WorldRequest
    {
        $membership = OrganizationMembership::factory()->create();
        $request = WorldRequest::factory()->approved()->create([
            'organization_id' => $membership->organization_id, 'user_id' => $membership->user_id,
            'approval_mail_revision' => 1,
        ]);
        $request->world->server->forceFill(['world_request_id' => $request->id, 'name' => 'happy-grove',
            'hetzner_name' => 'happy-grove', 'hetzner_reference' => (string) Str::uuid(), 'hetzner_server_id' => 42,
            'hetzner_status' => 'running'])->save();

        return $request->fresh();
    }

    private function terminate(WorldRequest $request): void
    {
        app(WorldRequestTermination::class)->terminate($request->applicant, $request, 'happy-grove', $request->revision);
    }

    public static function roles(): array
    {
        return ['applicant' => ['applicant'], 'manager' => ['manager'], 'admin' => ['admin'], 'caseworker' => ['caseworker']];
    }

    #[DataProvider('roles')]
    public function test_portal_and_admin_actions_require_the_server_name_and_queue_the_same_cleanup(string $role): void
    {
        $request = $this->approvedRequest();
        $actor = match ($role) {
            'applicant' => $request->applicant,
            'manager' => OrganizationMembership::factory()->manager()->create(['organization_id' => $request->organization_id])->user,
            'admin' => User::factory()->globalAdmin()->create(),
            'caseworker' => User::factory()->caseworker()->create(),
        };
        $this->actingAs($actor);
        $panel = in_array($role, ['admin', 'caseworker'], true) ? 'admin' : 'portal';
        Filament::setCurrentPanel(Filament::getPanel($panel));
        Filament::bootCurrentPanel();
        Filament::setTenant($panel === 'portal' ? $request->organization : null);
        $action = TestAction::make('terminate')->table($request);
        Livewire::test(WorldRequests::class)->callAction($action, ['confirmation_name' => 'wrong-name'])
            ->assertHasActionErrors(['confirmation_name']);
        $this->assertSame(WorldRequestStatus::Approved, $request->fresh()->status);
        Queue::assertNotPushed(TerminateWorldRequest::class);
        Livewire::test(WorldRequests::class)->callAction($action, ['confirmation_name' => 'happy-grove'])
            ->assertHasNoActionErrors()->assertActionHidden($action)
            ->assertActionHidden(TestAction::make('edit')->table($request));
        $this->assertSame(WorldRequestStatus::Terminated, $request->fresh()->status);
        $this->assertSame($actor->id, $request->fresh()->decided_by);
        $this->assertSame(2, $request->fresh()->revision);
        $this->assertSame(2, $request->fresh()->approved_access['generation']);
        Queue::assertPushed(TerminateWorldRequest::class, fn ($job): bool => $job->requestId === $request->id);
        Http::assertNothingSent();
    }

    public static function unauthorizedRoles(): array
    {
        return ['other member' => ['member'], 'outsider' => ['outsider'], 'inactive' => ['inactive'],
            'unverified' => ['unverified'], 'removed membership' => ['removed']];
    }

    #[DataProvider('unauthorizedRoles')]
    public function test_termination_does_not_grant_tenant_members_general_server_deletion_rights(string $role): void
    {
        $request = $this->approvedRequest();
        $actor = match ($role) {
            'member' => OrganizationMembership::factory()->create(['organization_id' => $request->organization_id])->user,
            'outsider' => User::factory()->create(),
            default => $request->applicant,
        };
        if ($role === 'inactive') {
            $actor->forceFill(['is_active' => false])->save();
        } elseif ($role === 'unverified') {
            $actor->forceFill(['email_verified_at' => null])->save();
        } elseif ($role === 'removed') {
            $actor->memberships()->delete();
        }
        try {
            app(WorldRequestTermination::class)->terminate($actor, $request, 'happy-grove', 1);
            $this->fail('Unauthorized termination was accepted.');
        } catch (AuthorizationException|HttpException $exception) {
            if ($exception instanceof HttpException) {
                $this->assertSame(403, $exception->getStatusCode());
            }
            $this->assertSame(WorldRequestStatus::Approved, $request->fresh()->status);
        }
        Queue::assertNotPushed(TerminateWorldRequest::class);
        Http::assertNothingSent();
    }

    public function test_backend_rejects_incorrect_names_and_stale_forms_without_mutating_the_request(): void
    {
        $request = $this->approvedRequest();
        foreach ([['HAPPY-GROVE', 1, 'confirmation_name'], ['happy-grove', 9, 'revision']] as [$name, $revision, $field]) {
            try {
                app(WorldRequestTermination::class)->terminate($request->applicant, $request, $name, $revision);
                $this->fail('Invalid confirmation was accepted.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey($field, $exception->errors());
            }
        }
        $this->assertSame(WorldRequestStatus::Approved, $request->fresh()->status);
        Queue::assertNotPushed(TerminateWorldRequest::class);
    }

    public function test_termination_serializes_with_provisioning_and_account_transfers(): void
    {
        $request = $this->approvedRequest();
        foreach (['luanti:request-provisioning:', 'luanti:accounts:process:'] as $prefix) {
            $lock = Cache::lock($prefix.$request->id, 1260);
            $this->assertTrue($lock->get());
            try {
                $this->terminate($request);
                $this->fail('Termination ignored an active transfer.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('revision', $exception->errors());
            } finally {
                $lock->release();
            }
        }
        $this->assertSame(WorldRequestStatus::Approved, $request->fresh()->status);
        Queue::assertNotPushed(TerminateWorldRequest::class);
    }

    public function test_a_scheduled_request_can_be_terminated_before_any_vm_exists(): void
    {
        $membership = OrganizationMembership::factory()->create();
        $request = WorldRequest::factory()->create(['organization_id' => $membership->organization_id,
            'user_id' => $membership->user_id, 'status' => WorldRequestStatus::Approved,
            'luanti_world_template_id' => LuantiWorldTemplate::factory()->create(['luanti_server_id' => null])->id,
            'provisioning_configuration' => ['name' => 'happy-grove'], 'provisioning_starts_at' => now()->addDay()]);
        $this->terminate($request);
        app(WorldRequestProvisioning::class)->process($request->id);
        app(WorldRequestTermination::class)->process($request->id);
        $this->assertSame(WorldRequestStatus::Terminated, $request->fresh()->status);
        $this->assertDatabaseCount('luanti_servers', 0);
        Http::assertNothingSent();
    }

    public function test_cleanup_deletes_only_the_owned_vm_and_confirms_deletion_on_a_later_retry(): void
    {
        $request = $this->approvedRequest();
        $server = $request->provisionedServer;
        $server->forceFill(['public_https_configuration' => ['hostname' => 'happy-grove.example.test'],
            'dns_status' => 'ready', 'api_token' => 'private-token'])->save();
        $this->terminate($request);
        Http::fake(['https://api.hetzner.cloud/v1/servers/42' => Http::sequence()
            ->push(['server' => ['id' => 42, 'status' => 'running', 'labels' => ['managed-by' => 'luanti-portal',
                'portal-reference' => $server->hetzner_reference]]])
            ->push([], 202)->push([], 404)]);
        $job = new TerminateWorldRequest($request->id);
        $job->handle(app(WorldRequestTermination::class));
        $this->assertSame('deleting', $server->fresh()->hetzner_status);
        $job->handle(app(WorldRequestTermination::class));
        $this->assertSame('deleted', $server->fresh()->hetzner_status);
        $this->assertNull($server->fresh()->api_token);
        $this->assertTrue($request->currentGameInstance->fresh()->is_archived);
        Queue::assertPushed(CleanupLuantiDns::class, fn ($job): bool => $job->serverId === $server->id);
        Http::assertSentCount(3);
        Http::assertSent(fn (Request $http): bool => $http->method() === 'DELETE' && str_ends_with($http->url(), '/servers/42'));
        $job->handle(app(WorldRequestTermination::class));
        Http::assertSentCount(3);
    }

    public function test_cleanup_keeps_failures_visible_and_retries_without_recreating_the_vm(): void
    {
        $request = $this->approvedRequest();
        $server = $request->provisionedServer;
        $this->terminate($request);
        $remote = ['server' => ['id' => 42, 'status' => 'running', 'labels' => ['managed-by' => 'luanti-portal',
            'portal-reference' => $server->hetzner_reference]]];
        Http::fake(['https://api.hetzner.cloud/v1/servers/42' => Http::sequence()->push($remote)->push([], 423)->push([], 404)]);
        app(WorldRequestTermination::class)->process($request->id);
        $this->assertNotNull($request->fresh()->provisioning_error);
        $this->assertSame(WorldRequestStatus::Terminated, $request->fresh()->status);
        app(WorldRequestProvisioning::class)->process($request->id);
        app(WorldRequestTermination::class)->process($request->id);
        $this->assertSame('deleted', $server->fresh()->hetzner_status);
        $this->assertNull($request->fresh()->provisioning_error);
        Http::assertSentCount(3);
    }

    public function test_cleanup_refuses_a_remote_vm_with_different_ownership_labels(): void
    {
        $request = $this->approvedRequest();
        $this->terminate($request);
        Http::fake(['https://api.hetzner.cloud/v1/servers/42' => Http::response(['server' => [
            'id' => 42, 'status' => 'running', 'labels' => ['managed-by' => 'luanti-portal', 'portal-reference' => 'someone-else'],
        ]])]);
        app(WorldRequestTermination::class)->process($request->id);
        $this->assertNotNull($request->fresh()->provisioning_error);
        Http::assertNotSent(fn (Request $http): bool => $http->method() === 'DELETE');
    }

    public function test_deleted_servers_including_hidden_history_do_not_block_termination_or_trigger_api_calls(): void
    {
        $request = $this->approvedRequest();
        $server = $request->provisionedServer;
        $server->forceFill(['hetzner_status' => 'deleted'])->save();
        $server->delete();
        $this->terminate($request->fresh());
        app(WorldRequestTermination::class)->process($request->id);
        $this->assertSame(WorldRequestStatus::Terminated, $request->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_background_work_cannot_reactivate_terminated_requests_or_send_old_ready_emails(): void
    {
        Mail::fake();
        $request = $this->approvedRequest();
        app(WorldRequestMailings::class)->queueApproval($request);
        $mailId = DB::table('world_request_mails')->sole()->id;
        $this->terminate($request);
        app(LuantiAccounts::class)->reconcile($request->id);
        app(LuantiGameInstances::class)->reconcile($request->id);
        app(WorldRequestMailings::class)->deliver($mailId);
        $this->assertSame(0, LuantiAccountJob::query()->count());
        Mail::assertNothingSent();
        Http::assertNothingSent();
    }

    public function test_system_cleanup_cannot_delete_a_server_for_an_active_request(): void
    {
        $request = $this->approvedRequest();
        try {
            app(HetznerVirtualMachines::class)->deleteForTerminatedRequest($request);
            $this->fail('An active request authorized system deletion.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        Http::assertNothingSent();
    }

    public function test_shared_legacy_servers_are_not_deleted_by_a_single_request(): void
    {
        $request = $this->approvedRequest();
        $request->provisionedServer->forceFill(['world_request_id' => null])->save();
        try {
            app(WorldRequestTermination::class)->terminate($request->applicant, $request->fresh(), 'happy-grove', 1);
            $this->fail('A shared server was accepted for deletion.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('confirmation_name', $exception->errors());
            $this->assertStringContainsString('gemeinsamen Server', $exception->errors()['confirmation_name'][0]);
        }
        $this->assertSame(WorldRequestStatus::Approved, $request->fresh()->status);
        Queue::assertNotPushed(TerminateWorldRequest::class);

        Http::assertNothingSent();
    }

    public function test_termination_cancels_account_transfers_and_late_workers_cannot_submit_them(): void
    {
        $request = $this->approvedRequest();
        $job = LuantiAccountJob::factory()->create(['world_request_id' => $request->id, 'state' => 'pending']);
        $this->terminate($request);
        $this->assertSame('cancelled', $job->fresh()->state);
        $job->update(['state' => 'queued']);
        app(LuantiAccounts::class)->process($job->id);
        $this->assertSame('cancelled', $job->fresh()->state);
        Http::assertNothingSent();
    }

    public function test_the_scheduler_recovers_lost_deletion_jobs_but_skips_completed_deletions(): void
    {
        $request = $this->approvedRequest();
        $this->terminate($request);
        $finished = WorldRequest::factory()->create(['status' => WorldRequestStatus::Terminated]);
        $finished->world->server->forceFill(['world_request_id' => $finished->id, 'hetzner_status' => 'deleted'])->save();
        Queue::fake();
        $this->artisan('schedule:list')->assertSuccessful();
        $event = collect(app(Schedule::class)->events())->firstWhere('description', 'world-request-termination-recovery');
        $this->assertNotNull($event);
        $event->run(app());
        Queue::assertPushed(TerminateWorldRequest::class, 1);
        Queue::assertPushed(TerminateWorldRequest::class, fn ($job): bool => $job->requestId === $request->id);
        Http::assertNothingSent();
    }
}
