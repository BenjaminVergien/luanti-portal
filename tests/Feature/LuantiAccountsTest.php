<?php

namespace Tests\Feature;

use App\Enums\MembershipRole;
use App\Jobs\ProcessLuantiAccounts;
use App\Models\LuantiAccount;
use App\Models\LuantiAccountAssignment;
use App\Models\LuantiAccountJob;
use App\Models\LuantiServer;
use App\Models\LuantiWorldTemplate;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Models\WorldRequest;
use App\Services\LuantiAccounts;
use App\Services\WorldRequests;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Queue\QueueManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class LuantiAccountsTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private WorldRequest $worldRequest;

    private QueueManager $queueManager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-11 12:00:00', 'Europe/Berlin'));
        $this->actor = User::factory()->caseworker()->create();
        $server = LuantiServer::factory()->create(['api_base_url' => 'https://bridge.example.test/prefix/', 'api_token' => 'private-test-token']);
        $world = LuantiWorldTemplate::factory()->create(['luanti_server_id' => $server->id, 'template_identifier' => 'dev']);
        $this->worldRequest = WorldRequest::factory()->approved()->create(['luanti_world_template_id' => $world->id]);
        $this->queueManager = Queue::getFacadeRoot();
        Queue::fake();
        Http::preventStrayRequests();
    }

    /** @return list<array{username: string, password: string, role: string}> */
    private function rows(): array
    {
        return [
            ['username' => 'Teacher_1', 'password' => 'Teacher-Password-123', 'role' => 'facilitator'],
            ['username' => 'Player-2', 'password' => 'Player-Password-123', 'role' => 'participant'],
        ];
    }

    private function preparedJob(): LuantiAccountJob
    {
        $access = app(LuantiAccounts::class)->snapshot($this->worldRequest->fresh());
        $access['generation'] = $this->worldRequest->fresh()->approved_access['generation'];
        $this->worldRequest->forceFill(['approved_access' => $access])->save();
        app(LuantiAccounts::class)->create($this->actor, $this->worldRequest, $this->rows());
        app(LuantiAccounts::class)->reconcile($this->worldRequest->id);

        return LuantiAccountJob::query()->sole();
    }

    private function changeRequest(int $worldId, string $endDate): WorldRequest
    {
        $request = $this->worldRequest->fresh();
        app(WorldRequests::class)->update($this->actor, $request, [
            ...$request->only(['name', 'participant_count']), 'luanti_world_template_id' => $worldId,
            'start_date' => $request->start_date->toDateString(), 'end_date' => $endDate,
        ], $request->revision);

        return $request->fresh();
    }

    private function reapprove(int $worldId, string $endDate): WorldRequest
    {
        $request = $this->changeRequest($worldId, $endDate);
        app(WorldRequests::class)->decide($this->actor, $request, true, $request->revision);

        return $request->fresh();
    }

    /** @param array<string, mixed> $responses */
    private function fakeHttp(array $responses): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake($responses);
    }

    /** @return array<string, mixed> */
    private function completed(LuantiAccountJob $job, ?string $rejected = null, string $code = 'existing_account'): array
    {
        $payload = json_decode($job->request_payload, true, flags: JSON_THROW_ON_ERROR);

        return ['version' => 1, 'world_id' => $job->instance_identifier, 'job_id' => $job->job_id, 'state' => 'completed',
            'result' => ['version' => 2, 'job_id' => $job->job_id, 'status' => $rejected === null ? 'ok' : 'partial',
                'accounts' => array_map(fn (array $account): array => ['username' => $account['username'],
                    'status' => $account['username'] === $rejected ? 'rejected' : 'created',
                    ...($account['username'] === $rejected ? ['code' => $code] : [])], $payload['accounts'])]];
    }

    public function test_multiple_game_accounts_are_separate_from_portal_users_with_encrypted_hidden_passwords(): void
    {
        $userCount = User::query()->count();
        app(LuantiAccounts::class)->create($this->actor, $this->worldRequest, $this->rows());
        $this->assertDatabaseCount('luanti_accounts', 2);
        $this->assertDatabaseCount('users', $userCount);
        $account = LuantiAccount::query()->where('username', 'Teacher_1')->sole();
        $this->assertSame('teacher_1', $account->normalized_username);
        $this->assertSame('Teacher-Password-123', app(LuantiAccounts::class)->revealPassword($this->actor, $account));
        $this->assertNotSame('Teacher-Password-123', DB::table('luanti_accounts')->where('id', $account->id)->value('initial_password'));
        $this->assertArrayNotHasKey('initial_password', $account->toArray());
        Queue::assertPushed(ProcessLuantiAccounts::class);
        Http::assertNothingSent();
    }

    public static function invalidRows(): array
    {
        return ['empty batch' => [[]], 'invalid name' => [[['username' => 'Spieler ä', 'password' => 'Safe-Password-123', 'role' => 'participant']]],
            'name too long' => [[['username' => str_repeat('a', 21), 'password' => 'Safe-Password-123', 'role' => 'participant']]],
            'short password' => [[['username' => 'Player', 'password' => 'short', 'role' => 'participant']]],
            'invalid role' => [[['username' => 'Player', 'password' => 'Safe-Password-123', 'role' => 'admin']]],
            'duplicate casing' => [[['username' => 'Player', 'password' => 'Safe-Password-123', 'role' => 'participant'], ['username' => 'PLAYER', 'password' => 'Other-Password-123', 'role' => 'participant']]],
            'over batch limit' => [array_map(fn (int $index): array => ['username' => 'Player'.$index, 'password' => 'Safe-Password-123', 'role' => 'participant'], range(1, 101))]];
    }

    #[DataProvider('invalidRows')]
    public function test_invalid_batches_are_atomic(array $rows): void
    {
        try {
            app(LuantiAccounts::class)->create($this->actor, $this->worldRequest, $rows);
            $this->fail('Expected validation failure.');
        } catch (ValidationException $exception) {
            $this->assertNotEmpty($exception->errors());
        }
        $this->assertDatabaseCount('luanti_accounts', 0);
        Queue::assertNothingPushed();
    }

    public static function unauthorizedActors(): array
    {
        return ['ordinary member' => [['internal_role' => null]], 'inactive staff' => [['is_active' => false]], 'unverified staff' => [['email_verified_at' => null]]];
    }

    #[DataProvider('unauthorizedActors')]
    public function test_creation_and_password_reveal_recheck_current_internal_permissions(array $changes): void
    {
        app(LuantiAccounts::class)->create($this->actor, $this->worldRequest, $this->rows());
        $account = LuantiAccount::query()->firstOrFail();
        $this->actor->fresh()->forceFill($changes)->save();
        foreach (['create', 'reveal'] as $action) {
            try {
                if ($action === 'create') {
                    app(LuantiAccounts::class)->create($this->actor, $this->worldRequest, [['username' => 'Third', 'password' => 'Third-Password-123', 'role' => 'participant']]);
                } else {
                    app(LuantiAccounts::class)->revealPassword($this->actor, $account);
                }
                $this->fail('Expected authorization failure.');
            } catch (AuthorizationException|HttpException $exception) {
                $this->assertDatabaseCount('luanti_accounts', 2);
            }
        }
    }

    public function test_name_reservations_are_case_insensitive_within_one_game(): void
    {
        app(LuantiAccounts::class)->create($this->actor, $this->worldRequest, $this->rows());
        $this->expectException(ValidationException::class);
        app(LuantiAccounts::class)->create($this->actor, $this->worldRequest, [['username' => 'TEACHER_1', 'password' => 'Other-Password-123', 'role' => 'participant']]);
    }

    public function test_secure_transport_uses_encrypted_exact_v2_bytes_and_berlin_exclusive_end(): void
    {
        $this->worldRequest->forceFill(['start_date' => '2026-10-25', 'end_date' => '2026-10-25'])->save();
        $job = $this->preparedJob();
        $payload = json_decode($job->request_payload, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(2, $payload['version']);
        $this->assertSame($job->job_id, $payload['job_id']);
        $this->assertSame(['starts_at' => Carbon::parse('2026-10-25 00:00:00', 'Europe/Berlin')->timestamp,
            'ends_at' => Carbon::parse('2026-10-26 00:00:00', 'Europe/Berlin')->timestamp], $payload['accounts'][0]['access_window']);
        $this->assertSame(25 * 3600, $payload['accounts'][0]['access_window']['ends_at'] - $payload['accounts'][0]['access_window']['starts_at']);
        $this->assertNotSame($job->request_payload, DB::table('luanti_account_jobs')->where('id', $job->id)->value('request_payload'));
        $this->assertArrayNotHasKey('request_payload', $job->toArray());
        $this->fakeHttp(['*' => function (Request $request, array $options) use ($job): PromiseInterface {
            $this->assertSame('POST', $request->method());
            $this->assertSame($job->base_url.'/v1/worlds/'.$job->instance_identifier.'/accounts/jobs', $request->url());
            $this->assertSame($job->request_payload, $request->body());
            $this->assertTrue($request->hasHeader('Authorization', 'Bearer private-test-token'));
            $this->assertFalse($options['allow_redirects']);
            $this->assertTrue($options['verify']);
            $this->assertLessThanOrEqual(10, $options['timeout']);
            $this->assertLessThanOrEqual(5, $options['connect_timeout']);

            return Http::response($this->completed($job));
        }]);
        app(LuantiAccounts::class)->process($job->id);
        $this->assertSame('completed', $job->fresh()->state);
        $this->assertSame(2, LuantiAccountAssignment::query()->where('applied_admitted', true)->count());
    }

    /** @return array<string, array{string, int}> */
    public static function oversizedResponses(): array
    {
        $cases = [];
        foreach (['queued', 'pending'] as $state) {
            foreach ([200, 202, 400, 401, 404, 500] as $status) {
                $cases[$state.' '.$status] = [$state, $status];
            }
        }

        return $cases;
    }

    #[DataProvider('oversizedResponses')]
    public function test_oversized_submission_and_polling_replies_preserve_jobs_until_manual_recovery(string $state, int $status): void
    {
        $job = $this->preparedJob();
        $job->update(['state' => $state]);
        $payload = $job->request_payload;
        $assignments = LuantiAccountAssignment::query()->get()->map->getRawOriginal()->all();
        $this->fakeHttp(['*' => Http::response(str_repeat('x', 262145).'private-test-token', $status, ['Content-Length' => '1'])]);
        $accounts = app(LuantiAccounts::class);

        $accounts->process($job->id);

        $this->assertSame('unknown', $job->fresh()->state);
        $this->assertSame($payload, $job->fresh()->request_payload);
        $this->assertStringNotContainsString('private-test-token', $job->fresh()->error);
        $this->assertSame($assignments, LuantiAccountAssignment::query()->get()->map->getRawOriginal()->all());
        Http::assertSentCount(1);
        Queue::fake();
        (new ProcessLuantiAccounts($this->worldRequest->id))->handle($accounts);
        Queue::assertNothingPushed();
        $this->assertDatabaseCount('luanti_account_jobs', 1);
        Http::assertSentCount(1);

        $accounts->retry($this->actor, $this->worldRequest);
        $this->fakeHttp(['*' => function (Request $request) use ($job): PromiseInterface {
            $this->assertSame('GET', $request->method());
            $this->assertStringEndsWith('/'.$job->job_id, $request->url());

            return Http::response($this->completed($job));
        }]);
        $accounts->process($job->id);
        $this->assertSame('completed', $job->fresh()->state);
        $this->assertDatabaseCount('luanti_account_jobs', 1);
    }

    public function test_failed_post_retries_identical_bytes_and_job_id_without_duplicate_jobs(): void
    {
        $job = $this->preparedJob();
        $this->fakeHttp(['*' => Http::failedConnection()]);
        app(LuantiAccounts::class)->process($job->id);
        $this->assertSame('queued', $job->fresh()->state);
        $submittedAt = $job->fresh()->submitted_at;
        $this->assertNotNull($submittedAt);
        $bytes = $job->request_payload;
        $id = $job->job_id;
        $this->fakeHttp(['*' => function (Request $request) use ($bytes, $job): PromiseInterface {
            $this->assertSame($bytes, $request->body());

            return Http::response($this->completed($job));
        }]);
        app(LuantiAccounts::class)->reconcile($this->worldRequest->id);
        app(LuantiAccounts::class)->process($job->id);
        $this->assertSame($id, $job->fresh()->job_id);
        $this->assertTrue($submittedAt->equalTo($job->fresh()->submitted_at));
        $this->assertDatabaseCount('luanti_account_jobs', 1);
    }

    public function test_pending_job_is_polled_from_saved_world_after_service_restart(): void
    {
        $job = $this->preparedJob();
        $this->fakeHttp(['*' => Http::response(['version' => 1, 'world_id' => $job->instance_identifier, 'job_id' => $job->job_id, 'state' => 'pending', 'result_url' => 'https://evil.example/secret'], 202)]);
        app(LuantiAccounts::class)->process($job->id);
        $this->assertSame('pending', $job->fresh()->state);
        $this->fakeHttp(['*' => function (Request $request) use ($job): PromiseInterface {
            $this->assertSame('GET', $request->method());
            $this->assertSame($job->base_url.'/v1/worlds/'.$job->instance_identifier.'/accounts/jobs/'.$job->job_id, $request->url());

            return Http::response($this->completed($job));
        }]);
        $this->app->forgetInstance(LuantiAccounts::class);
        app(LuantiAccounts::class)->process($job->id);
        $this->assertSame('completed', $job->fresh()->state);
    }

    public function test_partial_results_retry_only_failed_accounts_using_new_job_id(): void
    {
        $job = $this->preparedJob();
        $this->fakeHttp(['*' => Http::response($this->completed($job, 'Player-2'))]);
        app(LuantiAccounts::class)->process($job->id);
        $this->assertSame(1, LuantiAccountAssignment::query()->where('applied_admitted', true)->count());
        app(LuantiAccounts::class)->retry($this->actor, $this->worldRequest);
        app(LuantiAccounts::class)->reconcile($this->worldRequest->id);
        $retry = LuantiAccountJob::query()->where('id', '!=', $job->id)->sole();
        $payload = json_decode($retry->request_payload, true, flags: JSON_THROW_ON_ERROR);
        $this->assertNotSame($job->job_id, $retry->job_id);
        $this->assertSame(['Player-2'], array_column($payload['accounts'], 'username'));
    }

    public function test_edits_keep_previous_access_until_reapproval_then_revoke_before_grant(): void
    {
        $job = $this->preparedJob();
        $this->fakeHttp(['*' => Http::response($this->completed($job))]);
        app(LuantiAccounts::class)->process($job->id);
        $previous = $this->worldRequest->fresh()->approved_access;
        $data = [...$this->worldRequest->only(['name', 'luanti_world_template_id', 'participant_count']), 'start_date' => '2026-10-12', 'end_date' => '2026-10-21'];
        app(WorldRequests::class)->update($this->actor, $this->worldRequest, $data, $this->worldRequest->fresh()->revision);
        $this->assertSame($previous, $this->worldRequest->fresh()->approved_access);
        app(LuantiAccounts::class)->reconcile($this->worldRequest->id);
        $this->assertDatabaseCount('luanti_account_jobs', 1);
        app(WorldRequests::class)->decide($this->actor, $this->worldRequest, true, $this->worldRequest->fresh()->revision);
        app(LuantiAccounts::class)->reconcile($this->worldRequest->id);
        $revoke = LuantiAccountJob::query()->where('id', '!=', $job->id)->sole();
        $this->assertSame('revoke', $revoke->operation);
        $this->assertFalse(json_decode($revoke->request_payload, true)['accounts'][0]['admitted']);
        $this->fakeHttp(['*' => Http::response($this->completed($revoke))]);
        app(LuantiAccounts::class)->process($revoke->id);
        app(LuantiAccounts::class)->reconcile($this->worldRequest->id);
        $grant = LuantiAccountJob::query()->latest('id')->firstOrFail();
        $this->assertSame('grant', $grant->operation);
        $this->assertGreaterThan($revoke->id, $grant->id);
        $payload = json_decode($grant->request_payload, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(Carbon::parse('2026-10-12', 'Europe/Berlin')->timestamp, $payload['accounts'][0]['access_window']['starts_at']);
    }

    public function test_unknown_outcome_stops_followup_grants_and_sanitizes_remote_content(): void
    {
        $job = $this->preparedJob();
        $this->fakeHttp(['*' => Http::response(['version' => 1, 'world_id' => 'wrong-world', 'job_id' => $job->job_id, 'state' => 'completed', 'result' => 'private-test-token Teacher-Password-123'])]);
        app(LuantiAccounts::class)->process($job->id);
        app(LuantiAccounts::class)->reconcile($this->worldRequest->id);
        $this->assertSame('unknown', $job->fresh()->state);
        $this->assertDatabaseCount('luanti_account_jobs', 1);
        $this->assertStringNotContainsString('private-test-token', json_encode($job->fresh()->toArray()));
        $this->assertStringNotContainsString('Teacher-Password-123', json_encode($job->fresh()->toArray()));
    }

    public static function accessBoundaries(): array
    {
        return ['before start' => ['2026-10-10 23:59:59', 'Noch nicht freigegeben'],
            'exact start' => ['2026-10-11 00:00:00', 'Freigegeben'],
            'last second' => ['2026-10-18 23:59:59', 'Freigegeben'],
            'exclusive end' => ['2026-10-19 00:00:00', 'Abgelaufen'],
            'late result' => ['2026-11-01 12:00:00', 'Abgelaufen']];
    }

    #[DataProvider('accessBoundaries')]
    public function test_account_status_uses_confirmed_utc_window_with_exclusive_end(string $time, string $expected): void
    {
        $job = $this->preparedJob();
        $this->travelTo(Carbon::parse($time, 'Europe/Berlin'));
        $this->fakeHttp(['*' => Http::response($this->completed($job))]);
        app(LuantiAccounts::class)->process($job->id);
        $this->assertSame($expected, app(LuantiAccounts::class)->status(LuantiAccount::query()->firstOrFail()));
    }

    public function test_spring_clock_change_uses_23_hour_local_calendar_day(): void
    {
        $this->worldRequest->forceFill(['start_date' => '2027-03-28', 'end_date' => '2027-03-28'])->save();
        $payload = json_decode($this->preparedJob()->request_payload, true, flags: JSON_THROW_ON_ERROR);
        $window = $payload['accounts'][0]['access_window'];
        $this->assertSame(23 * 3600, $window['ends_at'] - $window['starts_at']);
    }

    public function test_reapproval_waits_for_existing_pending_job_then_skips_intermediate_approved_windows(): void
    {
        $job = $this->preparedJob();
        $this->fakeHttp(['*' => Http::response(['version' => 1, 'world_id' => $job->instance_identifier, 'job_id' => $job->job_id, 'state' => 'pending'], 202)]);
        app(LuantiAccounts::class)->process($job->id);
        foreach (['2026-10-20', '2026-10-25'] as $endDate) {
            $current = $this->worldRequest->fresh();
            app(WorldRequests::class)->update($this->actor, $current, [...$current->only(['name', 'luanti_world_template_id', 'participant_count']),
                'start_date' => '2026-10-11', 'end_date' => $endDate], $current->revision);
            app(WorldRequests::class)->decide($this->actor, $current->fresh(), true, $current->fresh()->revision);
            app(LuantiAccounts::class)->reconcile($this->worldRequest->id);
            $this->assertDatabaseCount('luanti_account_jobs', 1);
        }
        $this->fakeHttp(['*' => Http::response($this->completed($job))]);
        app(LuantiAccounts::class)->process($job->id);
        app(LuantiAccounts::class)->reconcile($this->worldRequest->id);
        $revoke = LuantiAccountJob::query()->latest('id')->firstOrFail();
        $this->assertSame('revoke', $revoke->operation);
        $this->fakeHttp(['*' => Http::response($this->completed($revoke))]);
        app(LuantiAccounts::class)->process($revoke->id);
        app(LuantiAccounts::class)->reconcile($this->worldRequest->id);
        $grant = LuantiAccountJob::query()->latest('id')->firstOrFail();
        $this->assertSame('grant', $grant->operation);
        $this->assertSame(Carbon::parse('2026-10-26', 'Europe/Berlin')->timestamp,
            json_decode($grant->request_payload, true)['accounts'][0]['access_window']['ends_at']);
        $this->assertDatabaseCount('luanti_account_jobs', 3);
    }

    /** @return array<string, array{bool, int}> */
    public static function supersedingApprovals(): array
    {
        return ['new dates' => [false, 1], 'successive approvals' => [false, 2]];
    }

    #[DataProvider('supersedingApprovals')]
    public function test_unsent_superseded_jobs_are_cancelled_and_only_the_latest_approval_is_sent(bool $switchWorld, int $approvals): void
    {
        $job = $this->preparedJob();
        $worldId = $switchWorld
            ? LuantiWorldTemplate::factory()->create(['luanti_server_id' => $this->worldRequest->world->luanti_server_id])->id
            : $job->instance->luanti_world_template_id;
        $accounts = app(LuantiAccounts::class);
        for ($index = 0; $index < $approvals; $index++) {
            $current = $this->reapprove($worldId, '2026-10-'.(22 + $index));
            $this->assertSame('cancelled', $job->fresh()->state);
            $this->assertNull($job->fresh()->submitted_at);
            $accounts->process($job->id);
            Http::assertNothingSent();
            $accounts->reconcile($current->id);
            $accounts->reconcile($current->id);
            $job = LuantiAccountJob::query()->latest('id')->firstOrFail();
            $this->assertSame($current->approved_access['generation'], $job->generation);
            $this->assertSame($current->current_game_instance_id, $job->luanti_game_instance_id);
        }
        $this->fakeHttp(['*' => function (Request $request) use ($job): PromiseInterface {
            $this->assertSame('POST', $request->method());
            $this->assertSame($job->request_payload, $request->body());

            return Http::response($this->completed($job));
        }]);
        $this->app->forgetInstance(LuantiAccounts::class);
        (new ProcessLuantiAccounts($this->worldRequest->id))->handle(app(LuantiAccounts::class));
        $accounts->reconcile($this->worldRequest->id);
        $this->assertDatabaseCount('luanti_account_jobs', $approvals + 1);
        $this->assertSame('completed', $job->fresh()->state);
        $this->assertFalse($job->fresh()->result['superseded']);
        Http::assertSentCount(1);
    }

    /** @return array<string, array{string}> */
    public static function unsentJobEntryPoints(): array
    {
        return ['reconciliation' => ['reconcile'], 'direct processing' => ['process']];
    }

    #[DataProvider('unsentJobEntryPoints')]
    public function test_saved_superseded_unsent_job_is_cancelled_at_each_worker_entry_point(string $entryPoint): void
    {
        $job = $this->preparedJob();
        $request = $this->worldRequest->fresh();
        $request->forceFill(['approved_access' => [...$request->approved_access, 'generation' => $job->generation + 1]])->save();
        $accounts = app(LuantiAccounts::class);
        if ($entryPoint === 'reconcile') {
            $accounts->reconcile($request->id);
        } else {
            $accounts->process($job->id);
        }
        $this->assertSame('cancelled', $job->fresh()->state);
        $this->assertNull($job->fresh()->submitted_at);
        Http::assertNothingSent();
    }

    public function test_pending_edits_do_not_supersede_a_queued_approved_grant(): void
    {
        $job = $this->preparedJob();
        $approvedAccess = $this->worldRequest->fresh()->approved_access;
        $this->changeRequest($job->instance->luanti_world_template_id, '2026-10-22');
        $this->fakeHttp(['*' => Http::response($this->completed($job))]);
        app(LuantiAccounts::class)->process($job->id);
        app(LuantiAccounts::class)->reconcile($this->worldRequest->id);
        $this->assertSame($approvedAccess, $this->worldRequest->fresh()->approved_access);
        $this->assertSame('completed', $job->fresh()->state);
        $this->assertFalse($job->fresh()->result['superseded']);
        $this->assertDatabaseCount('luanti_account_jobs', 1);
        Http::assertSentCount(1);
    }

    /** @return array<string, array{bool, bool}> */
    public static function submittedSupersededJobs(): array
    {
        return ['pending, changed dates' => [false, false], 'lost response, changed dates' => [true, false]];
    }

    #[DataProvider('submittedSupersededJobs')]
    public function test_superseded_submitted_grants_are_only_polled_and_cleaned_up_before_current_grants(bool $lostResponse, bool $switchWorld): void
    {
        $job = $this->preparedJob();
        $this->fakeHttp(['*' => $lostResponse ? Http::failedConnection()
            : Http::response(['version' => 1, 'world_id' => $job->instance_identifier, 'job_id' => $job->job_id, 'state' => 'pending'], 202)]);
        $accounts = app(LuantiAccounts::class);
        $accounts->process($job->id);
        $this->assertNotNull($job->fresh()->submitted_at);
        $worldId = $switchWorld
            ? LuantiWorldTemplate::factory()->create(['luanti_server_id' => $this->worldRequest->world->luanti_server_id])->id
            : $job->instance->luanti_world_template_id;
        $current = $this->reapprove($worldId, '2026-10-22');
        $accounts->reconcile($current->id);
        $this->assertDatabaseCount('luanti_account_jobs', 1);
        Queue::fake();
        $this->fakeHttp(['*' => function (Request $request) use ($job): PromiseInterface {
            $this->assertSame('GET', $request->method());
            $this->assertSame($job->base_url.'/v1/worlds/'.$job->instance_identifier.'/accounts/jobs/'.$job->job_id, $request->url());
            $this->assertSame('', $request->body());

            return Http::response($this->completed($job));
        }]);
        $this->app->forgetInstance(LuantiAccounts::class);
        (new ProcessLuantiAccounts($current->id))->handle(app(LuantiAccounts::class));
        $this->assertTrue($job->fresh()->result['superseded']);
        $this->assertSame($current->approved_access, $current->fresh()->approved_access);
        Queue::assertPushed(ProcessLuantiAccounts::class, fn (ProcessLuantiAccounts $worker): bool => $worker->requestId === $current->id && $worker->delay === null);
        $accounts->reconcile($current->id);
        $revoke = LuantiAccountJob::query()->latest('id')->firstOrFail();
        $this->assertSame('revoke', $revoke->operation);
        $this->assertSame($job->luanti_game_instance_id, $revoke->luanti_game_instance_id);
        $this->assertSame($current->approved_access['generation'], $revoke->generation);
        $this->fakeHttp(['*' => Http::response($this->completed($revoke))]);
        $accounts->process($revoke->id);
        $this->assertSame(0, LuantiAccountAssignment::query()->where('applied_admitted', true)->count());
        $accounts->reconcile($current->id);
        $grant = LuantiAccountJob::query()->latest('id')->firstOrFail();
        $this->assertSame('grant', $grant->operation);
        $this->assertSame($current->current_game_instance_id, $grant->luanti_game_instance_id);
        $this->assertSame($current->approved_access['ends_at'], json_decode($grant->request_payload, true)['accounts'][0]['access_window']['ends_at']);
        $this->assertDatabaseCount('luanti_account_jobs', 3);
    }

    public function test_superseded_success_with_matching_world_and_dates_keeps_access(): void
    {
        $job = $this->preparedJob();
        $job->update(['state' => 'pending', 'submitted_at' => now()]);
        $approvedAccess = $this->worldRequest->fresh()->approved_access;
        $this->changeRequest($job->instance->luanti_world_template_id, '2026-10-22');
        $current = $this->reapprove($job->instance->luanti_world_template_id, '2026-10-18');
        $this->assertSame($approvedAccess['ends_at'], $current->approved_access['ends_at']);
        $this->fakeHttp(['*' => Http::response($this->completed($job))]);
        app(LuantiAccounts::class)->process($job->id);
        app(LuantiAccounts::class)->reconcile($current->id);
        $this->assertTrue($job->fresh()->result['superseded']);
        $this->assertSame(2, LuantiAccountAssignment::query()->where('applied_admitted', true)->count());
        $this->assertDatabaseCount('luanti_account_jobs', 1);
    }

    public function test_unsent_revocation_is_cancelled_when_reapproval_restores_the_applied_window(): void
    {
        $job = $this->preparedJob();
        $this->fakeHttp(['*' => Http::response($this->completed($job))]);
        $accounts = app(LuantiAccounts::class);
        $accounts->process($job->id);
        $this->reapprove($job->instance->luanti_world_template_id, '2026-10-22');
        $accounts->reconcile($this->worldRequest->id);
        $revoke = LuantiAccountJob::query()->latest('id')->firstOrFail();
        $this->assertSame('revoke', $revoke->operation);
        $this->reapprove($job->instance->luanti_world_template_id, '2026-10-18');
        $accounts->process($revoke->id);
        $accounts->reconcile($this->worldRequest->id);
        $this->assertSame('cancelled', $revoke->fresh()->state);
        $this->assertSame(2, LuantiAccountAssignment::query()->where('applied_admitted', true)->count());
        $this->assertDatabaseCount('luanti_account_jobs', 2);
        Http::assertSentCount(1);
    }

    public function test_completion_reloads_the_approval_before_recording_remote_effects(): void
    {
        $job = $this->preparedJob();
        $this->fakeHttp(['*' => function () use ($job): PromiseInterface {
            $request = $this->worldRequest->fresh();
            $request->forceFill(['approved_access' => [...$request->approved_access, 'generation' => $job->generation + 1,
                'ends_at' => Carbon::parse('2026-10-23', 'Europe/Berlin')->timestamp]])->save();

            return Http::response($this->completed($job));
        }]);
        $accounts = app(LuantiAccounts::class);
        $accounts->process($job->id);
        $this->assertTrue($job->fresh()->result['superseded']);
        $this->assertSame($job->generation + 1, $this->worldRequest->fresh()->approved_access['generation']);
        $accounts->reconcile($this->worldRequest->id);
        $this->assertSame('revoke', LuantiAccountJob::query()->latest('id')->firstOrFail()->operation);
    }

    #[DataProvider('stalePartialFailures')]
    public function test_stale_successful_access_is_revoked_despite_other_account_failures(string $code): void
    {
        $job = $this->preparedJob();
        $job->update(['state' => 'pending', 'submitted_at' => now()]);
        $current = $this->reapprove($job->instance->luanti_world_template_id, '2026-10-22');
        $accounts = app(LuantiAccounts::class);
        $this->fakeHttp(['*' => Http::response($this->completed($job, 'Player-2', $code))]);
        $accounts->process($job->id);
        $accounts->reconcile($current->id);
        $revoke = LuantiAccountJob::query()->latest('id')->firstOrFail();
        $this->assertSame('revoke', $revoke->operation);
        $this->assertSame(['Teacher_1'], array_column(json_decode($revoke->request_payload, true)['accounts'], 'username'));
        $this->fakeHttp(['*' => Http::response($this->completed($revoke))]);
        $accounts->process($revoke->id);
        $accounts->reconcile($current->id);
        $this->assertDatabaseCount('luanti_account_jobs', 2);
        $this->assertFalse(LuantiAccountAssignment::query()->where('normalized_username', 'teacher_1')->sole()->applied_admitted);
        $this->assertSame($code, LuantiAccountAssignment::query()->where('normalized_username', 'player-2')->sole()->error);
    }

    /** @return array<string, array{string}> */
    public static function stalePartialFailures(): array
    {
        return ['definite rejection' => ['protected_account'], 'uncertain runtime failure' => ['storage_error']];
    }

    public function test_missing_superseded_remote_job_remains_unknown_and_is_never_reposted(): void
    {
        $job = $this->preparedJob();
        $job->update(['submitted_at' => now()]);
        $current = $this->reapprove($job->instance->luanti_world_template_id, '2026-10-22');
        $this->fakeHttp(['*' => function (Request $request): PromiseInterface {
            $this->assertSame('GET', $request->method());

            return Http::response([], 404);
        }]);
        $accounts = app(LuantiAccounts::class);
        $accounts->process($job->id);
        $this->assertSame('unknown', $job->fresh()->state);
        $accounts->retry($this->actor, $current);
        $accounts->process($job->id);
        $accounts->reconcile($current->id);
        $this->assertSame('unknown', $job->fresh()->state);
        $this->assertDatabaseCount('luanti_account_jobs', 1);
        Http::assertSentCount(2);
    }

    public function test_submission_records_attempt_before_transport_and_holds_the_request_lock(): void
    {
        $job = $this->preparedJob();
        $transactionLevel = DB::transactionLevel();
        $this->fakeHttp(['*' => function () use ($job, $transactionLevel): PromiseInterface {
            $this->assertNotNull($job->fresh()->submitted_at);
            $this->assertSame($transactionLevel, DB::transactionLevel());
            $this->assertFalse(Cache::lock('luanti:accounts:process:'.$job->world_request_id, 60)->get());

            return Http::response($this->completed($job));
        }]);
        app(LuantiAccounts::class)->process($job->id);
        $this->assertSame('completed', $job->fresh()->state);
    }

    public function test_approval_waits_for_submission_lock_and_preserves_state_on_timeout(): void
    {
        $job = $this->preparedJob();
        $request = $this->changeRequest($job->instance->luanti_world_template_id, '2026-10-22');
        $attributes = $request->getRawOriginal();
        $lock = Cache::lock('luanti:accounts:process:'.$request->id, 60);
        $this->assertTrue($lock->get());
        Sleep::fake(syncWithCarbon: true);
        try {
            app(LuantiAccounts::class)->process($job->id);
            Http::assertNothingSent();
            app(WorldRequests::class)->decide($this->actor, $request, true, $request->revision);
            $this->fail('Expected the decision to time out while submission holds the request lock.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('revision', $exception->errors());
            $this->assertSame($attributes, $request->fresh()->getRawOriginal());
            $this->assertSame('queued', $job->fresh()->state);
            $this->assertNull($job->fresh()->submitted_at);
        } finally {
            Sleep::fake(false);
            $lock->release();
        }
        app(WorldRequests::class)->decide($this->actor, $request, true, $request->revision);
        $this->assertSame('cancelled', $job->fresh()->state);
    }

    public function test_approval_acquires_the_request_lock_before_starting_its_transaction(): void
    {
        $job = $this->preparedJob();
        $request = $this->changeRequest($job->instance->luanti_world_template_id, '2026-10-22');
        $transactionLevel = DB::transactionLevel();
        $lock = Cache::lock('luanti:accounts:process:'.$request->id, 60);
        $this->assertTrue($lock->get());
        Sleep::fake(syncWithCarbon: true);
        Sleep::whenFakingSleep(function () use ($lock, $transactionLevel): void {
            $this->assertSame($transactionLevel, DB::transactionLevel());
            $lock->release();
        });
        try {
            app(WorldRequests::class)->decide($this->actor, $request, true, $request->revision);
            Sleep::assertSleptTimes(1);
        } finally {
            Sleep::fake(false);
            $lock->release();
        }
        $this->assertSame('cancelled', $job->fresh()->state);
        $this->assertSame($job->generation + 1, $request->fresh()->approved_access['generation']);
    }

    public function test_reapproval_defers_account_dispatch_until_game_schedule_is_reconciled(): void
    {
        $job = $this->preparedJob();
        Queue::getFacadeRoot()->releaseUniqueJobLocks();
        Queue::swap($this->queueManager);
        config(['queue.default' => 'database']);
        Event::listen(JobQueued::class, function (JobQueued $event) use ($job): void {
            if ($event->job instanceof ProcessLuantiAccounts) {
                $lock = Cache::lock('luanti:accounts:process:'.$job->world_request_id, 60);
                $this->assertTrue($lock->get());
                $lock->release();
            }
        });
        $this->reapprove($job->instance->luanti_world_template_id, '2026-10-22');
        $this->assertSame('cancelled', $job->fresh()->state);
        $this->assertDatabaseCount('jobs', 0);
    }

    public function test_migration_preserves_legacy_active_jobs_as_potentially_submitted(): void
    {
        $job = $this->preparedJob();
        $jobs = [];
        foreach (['pending', 'unknown', 'completed', 'cancelled'] as $state) {
            $jobs[$state] = LuantiAccountJob::factory()->create(['state' => $state]);
        }
        $payload = $job->request_payload;
        $migration = require database_path('migrations/2026_10_16_135210_add_submitted_at_to_luanti_account_jobs_table.php');
        $migration->down();
        $migration->up();
        $this->assertNotNull($job->fresh()->submitted_at);
        $this->assertSame($payload, $job->fresh()->request_payload);
        foreach ($jobs as $state => $legacyJob) {
            $this->assertSame($state, $legacyJob->fresh()->state);
            $this->assertSame(in_array($state, ['pending', 'unknown'], true), $legacyJob->fresh()->submitted_at !== null);
        }
        $this->reapprove($job->instance->luanti_world_template_id, '2026-10-22');
        $this->fakeHttp(['*' => function (Request $request) use ($job): PromiseInterface {
            $this->assertSame('GET', $request->method());

            return Http::response($this->completed($job));
        }]);
        $this->app->forgetInstance(LuantiAccounts::class);
        app(LuantiAccounts::class)->process($job->id);
        $this->assertTrue($job->fresh()->result['superseded']);
        $this->assertSame('completed', $job->fresh()->state);
    }

    public function test_old_bridge_contract_rejection_requires_upgrade_without_raw_remote_content(): void
    {
        $job = $this->preparedJob();
        $this->fakeHttp(['*' => Http::response(['version' => 1, 'error' => ['code' => 'invalid_request'], 'secret' => 'private-test-token'], 400)]);
        app(LuantiAccounts::class)->process($job->id);
        $this->assertSame('completed', $job->fresh()->state);
        $this->assertStringContainsString('Version 2', $job->fresh()->error);
        $this->assertStringNotContainsString('private-test-token', $job->fresh()->error);
        $this->assertSame(0, LuantiAccountAssignment::query()->where('applied_admitted', true)->count());
        app(LuantiAccounts::class)->reconcile($this->worldRequest->id);
        $this->assertDatabaseCount('luanti_account_jobs', 1);
    }

    public function test_recovery_dispatches_only_request_ids_without_plaintext_credentials(): void
    {
        $job = $this->preparedJob();
        Queue::fake();
        $this->artisan('luanti:accounts:recover')->assertSuccessful();
        Queue::assertPushed(ProcessLuantiAccounts::class, function (ProcessLuantiAccounts $worker) use ($job): bool {
            $this->assertSame($job->world_request_id, $worker->requestId);
            $this->assertStringNotContainsString('Teacher-Password-123', serialize($worker));
            $this->assertStringNotContainsString('private-test-token', serialize($worker));

            return true;
        });
    }

    public function test_plain_http_configuration_sends_no_credentials(): void
    {
        $this->worldRequest->world->server->update(['api_base_url' => 'http://bridge.example.test']);
        $job = $this->preparedJob();
        app(LuantiAccounts::class)->process($job->id);
        Http::assertNothingSent();
        $this->assertNotNull($job->fresh()->error);
        $this->assertSame('queued', $job->fresh()->state);
    }

    public function test_transport_redirect_is_never_followed_and_does_not_grant_access(): void
    {
        $job = $this->preparedJob();
        $this->fakeHttp(['*' => Http::response('', 302, ['Location' => 'https://evil.example/capture'])]);
        app(LuantiAccounts::class)->process($job->id);
        Http::assertSentCount(1);
        $this->assertSame('queued', $job->fresh()->state);
        $this->assertSame(0, LuantiAccountAssignment::query()->where('applied_admitted', true)->count());
    }

    public function test_unknown_retry_only_polls_existing_id_without_new_account_payload(): void
    {
        $job = $this->preparedJob();
        $this->fakeHttp(['*' => Http::response(['version' => 1, 'world_id' => $job->instance_identifier, 'job_id' => $job->job_id, 'state' => 'outcome_unknown'])]);
        app(LuantiAccounts::class)->process($job->id);
        $this->assertSame('unknown', $job->fresh()->state);
        app(LuantiAccounts::class)->retry($this->actor, $this->worldRequest);
        $this->fakeHttp(['*' => function (Request $request) use ($job): PromiseInterface {
            $this->assertSame('GET', $request->method());
            $this->assertSame('', $request->body());
            $this->assertStringEndsWith('/'.$job->job_id, $request->url());

            return Http::response($this->completed($job));
        }]);
        app(LuantiAccounts::class)->reconcile($this->worldRequest->id);
        app(LuantiAccounts::class)->process($job->id);
        $this->assertDatabaseCount('luanti_account_jobs', 1);
        $this->assertSame('completed', $job->fresh()->state);
    }

    public function test_runtime_storage_failure_requires_revocation_before_changed_window_can_be_granted(): void
    {
        $job = $this->preparedJob();
        $this->fakeHttp(['*' => Http::response($this->completed($job, 'Player-2', 'storage_error'))]);
        app(LuantiAccounts::class)->process($job->id);
        $uncertain = LuantiAccountAssignment::query()->where('normalized_username', 'player-2')->sole();
        $this->assertTrue($uncertain->outcome_uncertain);
        $this->assertFalse($uncertain->is_provisioned);
        $current = $this->worldRequest->fresh();
        app(WorldRequests::class)->update($this->actor, $current, [...$current->only(['name', 'luanti_world_template_id', 'participant_count']),
            'start_date' => '2026-10-12', 'end_date' => '2026-10-22'], $current->revision);
        app(WorldRequests::class)->decide($this->actor, $current->fresh(), true, $current->fresh()->revision);
        app(LuantiAccounts::class)->retry($this->actor, $current->fresh());
        app(LuantiAccounts::class)->reconcile($current->id);
        $revoke = LuantiAccountJob::query()->latest('id')->firstOrFail();
        $this->assertSame('revoke', $revoke->operation);
        $payload = json_decode($revoke->request_payload, true, flags: JSON_THROW_ON_ERROR);
        $this->assertFalse($payload['accounts'][1]['admitted']);
        $this->assertSame('Player-Password-123', $payload['accounts'][1]['initial_password']);
        $this->assertArrayNotHasKey('initial_password', $payload['accounts'][0]);
        $this->fakeHttp(['*' => Http::response($this->completed($revoke))]);
        app(LuantiAccounts::class)->process($revoke->id);
        $this->assertFalse($uncertain->fresh()->outcome_uncertain);
        app(LuantiAccounts::class)->reconcile($current->id);
        $grant = LuantiAccountJob::query()->latest('id')->firstOrFail();
        $this->assertSame('grant', $grant->operation);
        foreach (json_decode($grant->request_payload, true)['accounts'] as $entry) {
            $this->assertArrayNotHasKey('initial_password', $entry);
        }
    }

    public function test_same_window_runtime_rejection_retries_only_uncertain_account_without_unsafe_rename(): void
    {
        $job = $this->preparedJob();
        $this->fakeHttp(['*' => Http::response($this->completed($job, 'Player-2', 'storage_error'))]);
        app(LuantiAccounts::class)->process($job->id);
        $account = LuantiAccount::query()->where('username', 'Player-2')->sole();
        $this->assertFalse(app(LuantiAccounts::class)->canCorrect($account));
        try {
            app(LuantiAccounts::class)->correctRejected($this->actor, $account, ['username' => 'UnsafeRename', 'password' => 'New-Password-123']);
            $this->fail('Expected ambiguous creation to forbid rename.');
        } catch (ValidationException $exception) {
            $this->assertSame('Player-2', $account->fresh()->username);
        }
        app(LuantiAccounts::class)->retry($this->actor, $this->worldRequest);
        app(LuantiAccounts::class)->reconcile($this->worldRequest->id);
        $retry = LuantiAccountJob::query()->latest('id')->firstOrFail();
        $this->assertSame('grant', $retry->operation);
        $this->assertNotSame($job->job_id, $retry->job_id);
        $this->assertSame(['Player-2'], array_column(json_decode($retry->request_payload, true)['accounts'], 'username'));
    }

    public function test_repeated_recovery_and_worker_continuation_keep_one_database_queue_job_per_request(): void
    {
        Queue::swap($this->queueManager);
        config(['queue.default' => 'database']);
        LuantiAccount::factory()->create(['world_request_id' => $this->worldRequest->id]);
        $this->worldRequest->world->server->update(['api_base_url' => null]);
        $this->artisan('luanti:accounts:recover')->assertSuccessful();
        $this->artisan('luanti:accounts:recover')->assertSuccessful();
        $this->assertDatabaseCount('jobs', 1);

        $this->artisan('queue:work', ['connection' => 'database', '--once' => true, '--sleep' => 0])->assertSuccessful();
        $this->assertDatabaseCount('jobs', 1);
        $this->assertDatabaseCount('luanti_account_jobs', 1);
        $this->assertSame('queued', LuantiAccountJob::query()->sole()->state);
        $this->artisan('luanti:accounts:recover')->assertSuccessful();
        $this->assertDatabaseCount('jobs', 1);
        Http::assertNothingSent();
    }

    public static function institutionAccountManagers(): array
    {
        return ['applicant' => ['applicant'], 'institution manager' => ['manager']];
    }

    private function institutionActor(string $role): User
    {
        $actor = $role === 'applicant' ? $this->worldRequest->applicant : User::factory()->create();
        OrganizationMembership::factory()->create(['organization_id' => $this->worldRequest->organization_id,
            'user_id' => $actor->id, 'role' => $role === 'manager' ? MembershipRole::Manager : MembershipRole::Staff]);

        return $actor;
    }

    #[DataProvider('institutionAccountManagers')]
    public function test_applicant_and_current_manager_can_manage_own_institutions_approved_request(string $role): void
    {
        $actor = $this->institutionActor($role);
        $before = User::query()->count();
        app(LuantiAccounts::class)->create($actor, $this->worldRequest, $this->rows());
        $this->assertDatabaseCount('users', $before);
        app(LuantiAccounts::class)->reconcile($this->worldRequest->id);
        $job = LuantiAccountJob::query()->sole();
        $this->fakeHttp(['*' => Http::response($this->completed($job, 'Player-2'))]);
        app(LuantiAccounts::class)->process($job->id);
        $account = LuantiAccount::query()->where('username', 'Player-2')->sole();
        $this->assertSame('Player-Password-123', app(LuantiAccounts::class)->revealPassword($actor, $account));
        app(LuantiAccounts::class)->correctRejected($actor, $account, ['username' => 'Corrected', 'password' => 'Corrected-Password-123']);
        $this->assertSame('Corrected', $account->fresh()->username);
        app(LuantiAccounts::class)->retry($actor, $this->worldRequest);
        Queue::assertPushed(ProcessLuantiAccounts::class);
    }

    public static function institutionRevocations(): array
    {
        return ['applicant membership removed' => ['applicant', 'membership'], 'applicant inactive' => ['applicant', 'inactive'],
            'applicant unverified' => ['applicant', 'unverified'], 'manager membership removed' => ['manager', 'membership'],
            'manager demoted' => ['manager', 'demotion'], 'manager inactive' => ['manager', 'inactive'], 'manager unverified' => ['manager', 'unverified']];
    }

    #[DataProvider('institutionRevocations')]
    public function test_all_management_services_recheck_institution_membership_role_and_active_verified_state(string $role, string $revocation): void
    {
        $actor = $this->institutionActor($role);
        app(LuantiAccounts::class)->create($actor, $this->worldRequest, $this->rows());
        $account = LuantiAccount::query()->where('username', 'Player-2')->sole();
        $account->assignments()->update(['error' => 'existing_account']);
        if ($revocation === 'membership') {
            $actor->memberships()->where('organization_id', $this->worldRequest->organization_id)->delete();
        } elseif ($revocation === 'demotion') {
            $actor->memberships()->where('organization_id', $this->worldRequest->organization_id)->update(['role' => MembershipRole::Staff]);
        } else {
            $actor->fresh()->forceFill($revocation === 'inactive' ? ['is_active' => false] : ['email_verified_at' => null])->save();
        }
        $this->assertManagementDenied($actor, $account);
    }

    private function assertManagementDenied(User $actor, LuantiAccount $account): void
    {
        Queue::fake();
        foreach (['create', 'retry', 'reveal', 'correct'] as $action) {
            try {
                match ($action) {
                    'create' => app(LuantiAccounts::class)->create($actor, $this->worldRequest, [['username' => 'Blocked', 'password' => 'Blocked-Password-123', 'role' => 'participant']]),
                    'retry' => app(LuantiAccounts::class)->retry($actor, $this->worldRequest),
                    'reveal' => app(LuantiAccounts::class)->revealPassword($actor, $account),
                    'correct' => app(LuantiAccounts::class)->correctRejected($actor, $account, ['username' => 'Blocked', 'password' => 'Blocked-Password-123']),
                };
                $this->fail('Expected authorization failure for '.$action.'.');
            } catch (AuthorizationException|HttpException $exception) {
                $this->assertDatabaseCount('luanti_accounts', 2);
                $this->assertSame('Player-2', $account->fresh()->username);
            }
        }
        Queue::assertNothingPushed();
    }

    public static function unrelatedInstitutionActors(): array
    {
        return ['other staff member' => ['staff'], 'manager of other institution' => ['other_manager'], 'applicant with no current membership' => ['former_applicant']];
    }

    #[DataProvider('unrelatedInstitutionActors')]
    public function test_other_staff_other_institution_managers_and_former_members_have_no_account_management_services(string $role): void
    {
        app(LuantiAccounts::class)->create($this->actor, $this->worldRequest, $this->rows());
        $account = LuantiAccount::query()->where('username', 'Player-2')->sole();
        if ($role === 'former_applicant') {
            $actor = $this->worldRequest->applicant;
        } else {
            $actor = User::factory()->create();
            $factory = OrganizationMembership::factory();
            if ($role === 'other_manager') {
                $factory->manager()->create(['user_id' => $actor->id]);
            } else {
                $factory->create(['organization_id' => $this->worldRequest->organization_id, 'user_id' => $actor->id]);
            }
        }
        $this->assertManagementDenied($actor, $account);
    }
}
