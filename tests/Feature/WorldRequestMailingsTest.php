<?php

namespace Tests\Feature;

use App\Jobs\SendWorldRequestMail;
use App\Mail\WorldRequestStatusMail;
use App\Models\LuantiAccountJob;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Models\WorldRequest;
use App\Services\LuantiAccounts;
use App\Services\WorldRequestMailings;
use App\Services\WorldRequests;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

class WorldRequestMailingsTest extends TestCase
{
    use RefreshDatabase;

    public function createApplication(): Application
    {
        $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
        $app->afterBootstrapping(LoadConfiguration::class, function (Application $app): void {
            $app['config']->set('app.url', 'https://portal.example.test:8443');
        });
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Mail::fake();
        Http::preventStrayRequests();
    }

    private function provisionedRequest(): WorldRequest
    {
        $membership = OrganizationMembership::factory()->create();
        $request = WorldRequest::factory()->approved()->create([
            'organization_id' => $membership->organization_id, 'user_id' => $membership->user_id,
            'provisioning_configuration' => ['name' => 'happy-grove'], 'approval_mail_revision' => 2,
        ]);
        app(WorldRequestMailings::class)->ensureFacilitatorAccount($request);

        return $request->fresh();
    }

    private function confirmAccount(WorldRequest $request): void
    {
        $access = $request->approved_access;
        $request->facilitatorAccount->assignments()->sole()->update([
            'is_provisioned' => true, 'applied_admitted' => true, 'outcome_uncertain' => false,
            'applied_window' => ['starts_at' => $access['starts_at'], 'ends_at' => $access['ends_at']],
        ]);
    }

    public function test_approval_queues_one_german_mail_to_the_applicant_with_a_canonical_link(): void
    {
        $request = WorldRequest::factory()->create(['name' => 'Bauprojekt']);
        app(WorldRequests::class)->decide(User::factory()->globalAdmin()->create(), $request, true, 1);
        $service = app(WorldRequestMailings::class);
        $service->queueApproval($request->fresh());
        $this->assertDatabaseCount('world_request_mails', 1);
        $mailId = DB::table('world_request_mails')->sole()->id;
        Queue::assertPushed(SendWorldRequestMail::class);
        $service->deliver($mailId);
        $service->deliver($mailId);
        Mail::assertSent(WorldRequestStatusMail::class, function (WorldRequestStatusMail $mail) use ($request): bool {
            $mail->assertHasSubject('Dein Weltantrag wurde genehmigt · Luanti-Portal');
            $mail->assertSeeInHtml('Bauprojekt');
            $mail->assertSeeInText('wurde genehmigt');
            $mail->assertSeeInText('bis einschließlich');
            $this->assertStringStartsWith('https://portal.example.test:8443/portal/', $mail->details['portalUrl']);

            return $mail->hasTo($request->applicant->email);
        });
        Mail::assertSentCount(1);
        $this->assertNotNull(DB::table('world_request_mails')->sole()->accepted_at);
        Http::assertNothingSent();
    }

    public function test_rejection_does_not_send_an_approval_mail(): void
    {
        $request = WorldRequest::factory()->create();
        app(WorldRequests::class)->decide(User::factory()->caseworker()->create(), $request, false, 1);
        $this->assertDatabaseCount('world_request_mails', 0);
        Mail::assertNothingSent();
    }

    public function test_facilitator_credentials_are_created_once_encrypted_and_only_revealed_to_authorized_people(): void
    {
        $request = $this->provisionedRequest();
        $account = $request->facilitatorAccount;
        $password = app(LuantiAccounts::class)->revealPassword($request->applicant, $account);
        app(WorldRequestMailings::class)->ensureFacilitatorAccount($request);
        $this->assertSame('facilitator', $account->role);
        $this->assertSame(1, $request->luantiAccounts()->count());
        $this->assertGreaterThanOrEqual(24, strlen($password));
        $this->assertNotSame($password, $account->getRawOriginal('initial_password'));
        $this->assertArrayNotHasKey('initial_password', $account->toArray());
        $this->expectException(AuthorizationException::class);
        app(LuantiAccounts::class)->revealPassword(User::factory()->create(), $account);
    }

    public function test_ready_mail_waits_for_world_and_confirmed_facilitator_admission_and_contains_no_credentials(): void
    {
        $request = $this->provisionedRequest();
        $service = app(WorldRequestMailings::class);
        $service->queueReadyIfEnterable($request->id);
        $this->assertDatabaseCount('world_request_mails', 0);
        $this->confirmAccount($request);
        $request->currentGameInstance->update(['state' => 'stopped']);
        $service->queueReadyIfEnterable($request->id);
        $this->assertDatabaseCount('world_request_mails', 0);
        $request->currentGameInstance->update(['state' => 'running']);
        $service->queueReadyIfEnterable($request->id);
        $service->queueReadyIfEnterable($request->id);
        $this->assertDatabaseCount('world_request_mails', 1);
        $row = DB::table('world_request_mails')->sole();
        $password = $request->facilitatorAccount->initial_password;
        $username = $request->facilitatorAccount->username;
        $this->assertStringNotContainsString($password, $row->payload);
        $service->deliver($row->id);
        Mail::assertSent(WorldRequestStatusMail::class, function (WorldRequestStatusMail $mail) use ($password, $username): bool {
            $mail->assertSeeInHtml('eingerichtet und betretbar');
            $mail->assertSeeInText('Fachkraft-Spielaccount');
            $mail->assertSeeInText('Spielaccounts');
            $mail->assertDontSeeInHtml($password)->assertDontSeeInText($password);
            $mail->assertDontSeeInHtml($username)->assertDontSeeInText($username);
            $this->assertStringStartsWith('https://portal.example.test:8443/portal/', $mail->details['portalUrl']);

            return $mail->kind === 'ready';
        });
    }

    public function test_ready_mail_is_not_created_for_an_uncertain_assignment_or_outside_the_access_window(): void
    {
        $request = $this->provisionedRequest();
        $this->confirmAccount($request);
        $assignment = $request->facilitatorAccount->assignments()->sole();
        $assignment->update(['outcome_uncertain' => true]);
        app(WorldRequestMailings::class)->queueReadyIfEnterable($request->id);
        $this->assertDatabaseCount('world_request_mails', 0);
        $assignment->update(['outcome_uncertain' => false]);
        $this->travelTo(now()->setTimestamp($request->approved_access['ends_at']));
        app(WorldRequestMailings::class)->queueReadyIfEnterable($request->id);
        $this->assertDatabaseCount('world_request_mails', 0);
    }

    public function test_confirmed_bridge_account_completion_automatically_queues_the_ready_mail(): void
    {
        $request = $this->provisionedRequest();
        $request->currentGameInstance->server->update(['api_base_url' => 'https://bridge.example.test', 'api_token' => 'test-token']);
        app(LuantiAccounts::class)->reconcile($request->id);
        $job = LuantiAccountJob::query()->sole();
        Http::fake(['https://bridge.example.test/*' => Http::response([
            'version' => 1, 'world_id' => $job->instance_identifier, 'job_id' => $job->job_id, 'state' => 'completed',
            'result' => ['version' => 2, 'job_id' => $job->job_id, 'status' => 'ok',
                'accounts' => [['username' => $request->facilitatorAccount->username, 'status' => 'created']]],
        ])]);
        app(LuantiAccounts::class)->process($job->id);
        $this->assertSame('completed', $job->fresh()->state);
        $this->assertDatabaseHas('world_request_mails', ['world_request_id' => $request->id, 'kind' => 'ready']);
        Queue::assertPushed(SendWorldRequestMail::class);
    }

    public function test_smtp_failure_is_retained_for_retry_without_exposing_the_transport_secret(): void
    {
        $request = $this->provisionedRequest();
        $service = app(WorldRequestMailings::class);
        $service->queueApproval($request);
        $mailId = DB::table('world_request_mails')->sole()->id;
        $mailFake = Mail::getFacadeRoot();
        Mail::shouldReceive('to')->once()->andThrow(new RuntimeException('smtp://user:super-secret@host rejected'));
        try {
            $service->deliver($mailId);
            $this->fail('A failed SMTP submission was accepted.');
        } catch (RuntimeException $exception) {
            $this->assertStringNotContainsString('super-secret', $exception->getMessage());
            $this->assertNull($exception->getPrevious());
        }
        $mail = DB::table('world_request_mails')->sole();
        $this->assertNull($mail->accepted_at);
        $this->assertSame(1, $mail->attempts);
        $this->assertStringNotContainsString('super-secret', $mail->last_error);
        Queue::fake();
        $service->recover();
        Queue::assertNothingPushed();
        $this->travel(6)->minutes();
        $service->recover();
        Queue::assertPushed(SendWorldRequestMail::class);
        Mail::swap($mailFake);
        $service->deliver($mailId);
        $this->assertNotNull(DB::table('world_request_mails')->sole()->accepted_at);
    }
}
