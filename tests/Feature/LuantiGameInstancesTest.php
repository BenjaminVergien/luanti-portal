<?php

namespace Tests\Feature;

use App\Enums\WorldRequestStatus;
use App\Models\LuantiAccount;
use App\Models\LuantiAccountJob;
use App\Models\LuantiLifecycleJob;
use App\Models\LuantiServer;
use App\Models\LuantiWorldTemplate;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Models\WorldRequest;
use App\Services\LuantiAccounts;
use App\Services\LuantiGameInstances;
use App\Services\WorldRequests;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class LuantiGameInstancesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setTimezone('Europe/Berlin')->setDate(2026, 10, 16)->setTime(12, 0));
        Queue::fake();
        Http::preventStrayRequests();
    }

    private function request(): WorldRequest
    {
        $server = LuantiServer::factory()->create(['api_base_url' => 'https://bridge.example.test', 'api_token' => 'test-token']);
        $request = WorldRequest::factory()->create(['luanti_world_template_id' => LuantiWorldTemplate::factory()->create(['luanti_server_id' => $server->id])->id]);
        OrganizationMembership::factory()->create(['organization_id' => $request->organization_id, 'user_id' => $request->user_id]);

        return $request;
    }

    private function approve(WorldRequest $request): WorldRequest
    {
        app(WorldRequests::class)->decide(User::factory()->caseworker()->create(), $request, true, $request->revision);

        return $request->fresh();
    }

    private function bridge(): void
    {
        Http::fake(function (Request $request): PromiseInterface {
            $data = json_decode($request->body(), true, flags: JSON_THROW_ON_ERROR);
            $state = match ($data['operation']) {
                'archive_instance' => 'archived', 'start_instance' => 'running', default => 'stopped',
            };

            return Http::response(['version' => 1, 'job_id' => $data['job_id'], 'state' => 'completed',
                'result' => ['version' => 2, 'job_id' => $data['job_id'], 'instance_id' => $data['instance_id'], 'ok' => true, 'state' => $state]]);
        });
    }

    public function test_approval_allocates_private_identity_and_automatic_reconciliation_prepares_and_starts_it(): void
    {
        $request = $this->approve($this->request());
        $instance = $request->currentGameInstance;
        $this->assertSame('preparing', $instance->state);
        $this->assertNotSame($request->world->template_identifier, $instance->instance_identifier);
        $this->bridge();
        app(LuantiGameInstances::class)->reconcile($request->id);
        $this->assertTrue($instance->fresh()->is_prepared);
        $this->assertSame('running', $instance->fresh()->state);
        app(LuantiGameInstances::class)->reconcile($request->id);
        $this->assertDatabaseCount('luanti_game_instances', 1);
        $this->assertSame(1, LuantiLifecycleJob::where('operation', 'create_instance')->count());
    }

    public function test_two_applications_using_one_template_have_independent_username_reservations_without_a_participant_quota(): void
    {
        $first = $this->approve($this->request());
        $second = WorldRequest::factory()->approved()->create(['luanti_world_template_id' => $first->luanti_world_template_id, 'participant_count' => 1]);
        $second->currentGameInstance->update(['state' => 'running', 'is_prepared' => true]);
        $rows = [['username' => 'SameName', 'password' => 'Example-Password-123', 'role' => 'participant']];
        $actor = User::factory()->caseworker()->create();
        app(LuantiAccounts::class)->create($actor, $first, $rows);
        app(LuantiAccounts::class)->create($actor, $second, $rows);
        app(LuantiAccounts::class)->create($actor, $second, [['username' => 'AnotherName', 'password' => 'Example-Password-123', 'role' => 'participant']]);
        $this->assertDatabaseCount('luanti_accounts', 3);
        $this->assertNotSame($first->current_game_instance_id, $second->current_game_instance_id);
    }

    public function test_overlapping_approval_is_rejected_but_next_day_is_available(): void
    {
        $first = $this->approve($this->request());
        $second = WorldRequest::factory()->create(['luanti_world_template_id' => $first->luanti_world_template_id]);
        try {
            $this->approve($second);
            $this->fail('Expected server reservation conflict');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('start_date', $exception->errors());
        }
        $second->update(['start_date' => $first->end_date->addDay()->toDateString(), 'end_date' => $first->end_date->addDays(2)->toDateString()]);
        $this->assertSame(WorldRequestStatus::Approved, $this->approve($second)->status);
    }

    public function test_pending_date_edit_preserves_the_previous_booking_and_extension_reuses_save(): void
    {
        $request = $this->approve($this->request());
        $access = $request->approved_access;
        app(WorldRequests::class)->update($request->applicant, $request, [...$request->only(['name', 'luanti_world_template_id', 'participant_count']),
            'start_date' => $request->start_date->toDateString(), 'end_date' => $request->end_date->addDays(2)->toDateString()], $request->revision);
        $request = $request->fresh();
        $this->assertSame($access, $request->approved_access);
        $reapproved = $this->approve($request);
        $this->assertSame($request->current_game_instance_id, $reapproved->current_game_instance_id);
        $this->assertGreaterThan($access['ends_at'], $reapproved->approved_access['ends_at']);
        $this->assertDatabaseCount('luanti_game_instances', 1);
    }

    public function test_template_change_keeps_approval_and_roster_archives_old_game_and_cancels_its_jobs(): void
    {
        $request = $this->approve($this->request());
        $this->bridge();
        app(LuantiGameInstances::class)->reconcile($request->id);
        $old = $request->currentGameInstance;
        $account = LuantiAccount::factory()->create(['world_request_id' => $request->id]);
        app(LuantiAccounts::class)->reconcile($request->id);
        $job = LuantiAccountJob::sole();
        $template = LuantiWorldTemplate::factory()->create(['luanti_server_id' => $old->luanti_server_id]);
        app(WorldRequests::class)->changeTemplate($request->applicant, $request, $template->id, $request->revision);
        $this->assertSame($request->approved_access, $request->fresh()->approved_access);
        app(LuantiGameInstances::class)->reconcile($request->id);
        $current = $request->fresh();
        $this->assertSame(WorldRequestStatus::Approved, $current->status);
        $this->assertNotSame($old->id, $current->current_game_instance_id);
        $this->assertTrue($old->fresh()->is_archived);
        $this->assertSame('cancelled', $job->fresh()->state);
        $this->assertSame($request->approved_access['ends_at'], $current->approved_access['ends_at']);
        $this->assertSame($account->username, $current->luantiAccounts->sole()->username);
        $this->assertDatabaseCount('luanti_game_instances', 2);
    }

    public function test_other_organization_cannot_change_template(): void
    {
        $request = $this->approve($this->request());
        $outsider = User::factory()->create();
        $template = LuantiWorldTemplate::factory()->create(['luanti_server_id' => $request->world->luanti_server_id]);
        $this->expectException(AuthorizationException::class);
        app(WorldRequests::class)->changeTemplate($outsider, $request, $template->id, $request->revision);
    }

    public function test_uncertain_creation_retries_exact_bytes_without_allocating_another_game(): void
    {
        $request = $this->approve($this->request());
        Http::fake(['*' => Http::failedConnection()]);
        app(LuantiGameInstances::class)->reconcile($request->id);
        $job = LuantiLifecycleJob::sole();
        app(LuantiGameInstances::class)->reconcile($request->id);
        $this->assertDatabaseCount('luanti_game_instances', 1);
        $this->assertDatabaseCount('luanti_lifecycle_jobs', 1);
        $this->assertSame($job->request_payload, LuantiLifecycleJob::sole()->request_payload);
    }

    public function test_future_and_expired_games_are_stopped_and_retained(): void
    {
        $request = $this->request();
        $request->update(['start_date' => '2026-10-18', 'end_date' => '2026-10-18']);
        $request = $this->approve($request);
        $this->bridge();
        app(LuantiGameInstances::class)->reconcile($request->id);
        $this->assertSame('stopped', $request->currentGameInstance->fresh()->state);
        $this->travelTo(now()->setDate(2026, 10, 19));
        app(LuantiGameInstances::class)->reconcile($request->id);
        $this->assertDatabaseCount('luanti_game_instances', 1);
        $this->assertFalse($request->currentGameInstance->fresh()->is_archived);
        $this->assertSame(0, LuantiLifecycleJob::where('operation', 'start_instance')->count());
    }

    public function test_berlin_autumn_clock_change_creates_a_25_hour_day(): void
    {
        $request = $this->request();
        $request->update(['start_date' => '2026-10-25', 'end_date' => '2026-10-25']);
        $request = $this->approve($request);
        $this->assertSame(25 * 3600, $request->approved_access['ends_at'] - $request->approved_access['starts_at']);
    }

    public function test_concurrent_approvals_cannot_reserve_the_same_server_period(): void
    {
        $first = $this->request();
        $second = WorldRequest::factory()->create(['luanti_world_template_id' => $first->luanti_world_template_id]);
        $reviewer = User::factory()->caseworker()->create();
        $directory = storage_path('framework/testing/game-booking-'.bin2hex(random_bytes(8)));
        File::makeDirectory($directory, recursive: true);
        $database = $directory.'/booking.sqlite';
        $copy = new \PDO('sqlite:'.$database);
        $source = DB::connection()->getPdo();
        $schema = $source->query("SELECT name, type, sql FROM sqlite_master WHERE sql IS NOT NULL AND type IN ('table', 'index') AND name NOT LIKE 'sqlite_%' ORDER BY CASE type WHEN 'table' THEN 0 ELSE 1 END")->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($schema as $entry) {
            $copy->exec($entry['sql']);
            if ($entry['type'] === 'table') {
                foreach ($source->query('SELECT * FROM "'.$entry['name'].'"')->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                    $statement = $copy->prepare('INSERT INTO "'.$entry['name'].'" VALUES ('.implode(',', array_fill(0, count($row), '?')).')');
                    $statement->execute(array_values($row));
                }
            }
        }
        $copy = null;
        $script = <<<'PHP'
        require 'vendor/autoload.php';
        $app = require 'bootstrap/app.php';
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => getenv('GAME_TEST_DB'),
            'database.connections.sqlite.url' => null, 'cache.default' => 'file',
            'cache.stores.file.path' => getenv('GAME_TEST_CACHE'), 'cache.stores.file.lock_path' => getenv('GAME_TEST_CACHE')]);
        Illuminate\Support\Facades\DB::purge();
        $deadline = microtime(true) + 10;
        while (! file_exists(getenv('GAME_TEST_GATE')) && microtime(true) < $deadline) {
            usleep(10000);
        }
        try {
            app(App\Services\WorldRequests::class)->decide(App\Models\User::findOrFail(getenv('GAME_TEST_REVIEWER')),
                App\Models\WorldRequest::findOrFail(getenv('GAME_TEST_REQUEST')), true, 1);
            echo 'approved';
        } catch (Illuminate\Validation\ValidationException) {
            echo 'conflict';
        }
        PHP;
        $children = [];
        try {
            foreach ([$first, $second] as $request) {
                $environment = [...getenv(), 'APP_ENV' => 'testing', 'APP_KEY' => config('app.key'),
                    'GAME_TEST_DB' => $database, 'GAME_TEST_CACHE' => $directory.'/cache', 'GAME_TEST_GATE' => $directory.'/go',
                    'GAME_TEST_REVIEWER' => (string) $reviewer->id, 'GAME_TEST_REQUEST' => (string) $request->id];
                $process = proc_open([PHP_BINARY, '-r', $script], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), $environment);
                $this->assertIsResource($process);
                fclose($pipes[0]);
                $children[] = [$process, $pipes];
            }
            touch($directory.'/go');
            $results = [];
            foreach ($children as [$process, $pipes]) {
                $results[] = stream_get_contents($pipes[1]);
                $error = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                $this->assertSame(0, proc_close($process), $error);
            }
            sort($results);
            $this->assertSame(['approved', 'conflict'], $results);
        } finally {
            foreach ($children as [$process, $pipes]) {
                if (is_resource($process)) {
                    proc_terminate($process);
                    proc_close($process);
                }
            }
            File::deleteDirectory($directory);
        }
    }

    public function test_organization_can_confirm_template_change_in_the_portal(): void
    {
        $request = $this->approve($this->request());
        $template = LuantiWorldTemplate::factory()->create(['luanti_server_id' => $request->world->luanti_server_id]);
        $this->actingAs($request->applicant);
        Filament::setCurrentPanel(Filament::getPanel('portal'));
        Filament::bootCurrentPanel();
        Filament::setTenant($request->organization);
        Livewire::test(\App\Filament\Pages\WorldRequests::class)
            ->callAction(TestAction::make('changeTemplate')->table($request), [
                'luanti_world_template_id' => $template->id, 'revision' => $request->revision,
            ])->assertHasNoActionErrors();
        $this->assertSame($template->id, $request->fresh()->luanti_world_template_id);
        $this->assertSame(WorldRequestStatus::Approved, $request->fresh()->status);
    }

    public function test_development_reset_requires_explicit_force_and_preserves_users_organizations_and_servers(): void
    {
        $request = $this->approve($this->request());
        LuantiAccountJob::factory()->create(['world_request_id' => $request->id]);
        $users = User::count();
        $organizations = Organization::count();
        $servers = LuantiServer::count();
        $this->artisan('luanti:worlds:reset-development', ['--force' => true])->assertFailed();
        $this->app['env'] = 'local';
        $this->artisan('luanti:worlds:reset-development')->assertFailed();
        $this->assertDatabaseCount('world_requests', 1);
        $this->artisan('luanti:worlds:reset-development', ['--force' => true])->assertSuccessful();
        $this->assertDatabaseCount('world_requests', 0);
        $this->assertDatabaseCount('luanti_game_instances', 0);
        $this->assertDatabaseCount('luanti_accounts', 0);
        $this->assertDatabaseCount('users', $users);
        $this->assertDatabaseCount('organizations', $organizations);
        $this->assertDatabaseCount('luanti_servers', $servers);
    }

    public function test_failed_provisioning_can_retry_without_allocating_another_copy_and_hides_remote_error_text(): void
    {
        $request = $this->approve($this->request());
        Http::fake(function (Request $http): PromiseInterface {
            $data = json_decode($http->body(), true);

            return Http::response(['version' => 1, 'job_id' => $data['job_id'], 'state' => 'completed', 'result' => [
                'version' => 2, 'job_id' => $data['job_id'], 'instance_id' => $data['instance_id'], 'ok' => false,
                'error' => ['code' => 'copy_failed', 'message' => 'private remote details'],
            ]]);
        });
        app(LuantiGameInstances::class)->reconcile($request->id);
        $this->assertSame('failed', $request->currentGameInstance->fresh()->state);
        $this->assertStringNotContainsString('private remote details', LuantiLifecycleJob::sole()->toJson());
        app(LuantiGameInstances::class)->retry($request);
        Http::swap(new Factory);
        $this->bridge();
        app(LuantiGameInstances::class)->reconcile($request->id);
        $this->assertDatabaseCount('luanti_game_instances', 1);
        $this->assertSame('running', $request->currentGameInstance->fresh()->state);
    }

    public function test_rejection_disables_the_approved_schedule_with_a_new_generation_and_retains_the_save(): void
    {
        $request = $this->approve($this->request());
        $this->bridge();
        app(LuantiGameInstances::class)->reconcile($request->id);
        app(LuantiAccounts::class)->create($request->applicant, $request, [['username' => 'Player', 'password' => 'Example-Password-123', 'role' => 'participant']]);
        app(LuantiAccounts::class)->reconcile($request->id);
        app(WorldRequests::class)->update($request->applicant, $request, [...$request->only(['name', 'luanti_world_template_id', 'participant_count']),
            'start_date' => $request->start_date->toDateString(), 'end_date' => $request->end_date->addDay()->toDateString()], $request->revision);
        $pending = $request->fresh();
        app(WorldRequests::class)->decide(User::factory()->caseworker()->create(), $pending, false, $pending->revision);
        app(LuantiGameInstances::class)->reconcile($request->id);
        $this->assertSame('stopped', $request->currentGameInstance->fresh()->state);
        $this->assertSame('cancelled', LuantiAccountJob::sole()->state);
        $this->assertDatabaseCount('luanti_game_instances', 1);
        $configuration = json_decode(LuantiLifecycleJob::where('operation', 'configure_instance')->latest('id')->firstOrFail()->request_payload, true);
        $this->assertFalse($configuration['enabled']);
        $this->assertGreaterThan($request->approved_access['generation'], $configuration['generation']);
    }

    public function test_pending_lifecycle_jobs_are_polled_and_show_starting_before_running(): void
    {
        $request = $this->approve($this->request());
        Http::fake(function (Request $http): PromiseInterface {
            $data = $http->method() === 'POST' ? json_decode($http->body(), true)
                : json_decode(LuantiLifecycleJob::where('job_id', basename($http->url()))->sole()->request_payload, true);
            if ($http->method() === 'POST' && in_array($data['operation'], ['create_instance', 'start_instance'], true)) {
                return Http::response(['version' => 1, 'job_id' => $data['job_id'], 'state' => 'pending'], 202);
            }

            return Http::response(['version' => 1, 'job_id' => $data['job_id'], 'state' => 'completed', 'result' => [
                'version' => 2, 'job_id' => $data['job_id'], 'instance_id' => $data['instance_id'], 'ok' => true,
                'state' => $data['operation'] === 'start_instance' ? 'running' : 'stopped',
            ]]);
        });
        app(LuantiGameInstances::class)->reconcile($request->id);
        $this->assertSame('preparing', $request->currentGameInstance->fresh()->state);
        app(LuantiGameInstances::class)->reconcile($request->id);
        $this->assertSame('starting', $request->currentGameInstance->fresh()->state);
        app(LuantiGameInstances::class)->reconcile($request->id);
        $this->assertSame('running', $request->currentGameInstance->fresh()->state);
        $this->assertSame(1, LuantiLifecycleJob::where('operation', 'create_instance')->count());
        $this->assertDatabaseCount('luanti_game_instances', 1);
    }

    public function test_reapproval_during_template_replacement_keeps_access_bound_to_the_current_copy_until_cutover(): void
    {
        $request = $this->approve($this->request());
        $oldId = $request->current_game_instance_id;
        $template = LuantiWorldTemplate::factory()->create(['luanti_server_id' => $request->world->luanti_server_id]);
        app(WorldRequests::class)->changeTemplate($request->applicant, $request, $template->id, $request->revision);
        $changed = $request->fresh();
        app(WorldRequests::class)->update($changed->applicant, $changed, [...$changed->only(['name', 'luanti_world_template_id', 'participant_count']),
            'start_date' => $changed->start_date->toDateString(), 'end_date' => $changed->end_date->addDay()->toDateString()], $changed->revision);
        $approved = $this->approve($changed->fresh());
        $this->assertSame($oldId, $approved->approved_access['instance_id']);
        $this->assertSame($oldId, $approved->current_game_instance_id);
        $this->assertDatabaseCount('luanti_game_instances', 2);
        $this->bridge();
        app(LuantiGameInstances::class)->reconcile($request->id);
        $this->assertNotSame($oldId, $request->fresh()->current_game_instance_id);
        $this->assertSame($request->fresh()->current_game_instance_id, $request->fresh()->approved_access['instance_id']);
    }
}
