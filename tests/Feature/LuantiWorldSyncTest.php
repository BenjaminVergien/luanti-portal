<?php

namespace Tests\Feature;

use App\Enums\WorldRequestStatus;
use App\Filament\Pages\LuantiServers;
use App\Filament\Pages\LuantiWorlds;
use App\Filament\Pages\WorldRequests as WorldRequestsPage;
use App\Models\LuantiServer;
use App\Models\LuantiWorld;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Models\WorldRequest;
use App\Services\LuantiWorldRegistry;
use App\Services\LuantiWorldSynchronizer;
use App\Services\WorldRequests;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class LuantiWorldSyncTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private LuantiServer $server;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->globalAdmin()->create();
        $this->server = LuantiServer::factory()->create(['api_base_url' => 'https://bridge.example.test/prefix/',
            'api_token' => 'private-test-token']);
        Http::preventStrayRequests();
    }

    /** @return array{version: int, worlds: list<array{world_id: string, name: string}>} */
    private function catalogue(): array
    {
        return ['version' => 1, 'worlds' => [
            ['world_id' => 'dev', 'name' => 'Entwicklungskarte'],
            ['world_id' => 'test', 'name' => 'Testkarte'],
        ]];
    }

    private function panel(User $user, string $id = 'admin', ?Organization $organization = null): void
    {
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel($id));
        Filament::bootCurrentPanel();
        Filament::setTenant($organization);
    }

    public function test_sync_imports_saved_maps_using_one_secure_get_and_is_idempotent(): void
    {
        Http::fake(['https://bridge.example.test/prefix/v1/worlds' => function (Request $request, array $options): PromiseInterface {
            $this->assertSame(3, $options['connect_timeout']);
            $this->assertSame(8, $options['timeout']);
            $this->assertFalse($options['allow_redirects']);
            $this->assertTrue($options['verify']);
            $this->assertSame('GET', $request->method());
            $this->assertSame('', $request->body());
            $this->assertTrue($request->hasHeader('Authorization', 'Bearer private-test-token'));
            $this->assertTrue($request->hasHeader('Accept', 'application/json'));

            return Http::response($this->catalogue());
        }]);
        $service = app(LuantiWorldSynchronizer::class);
        $this->assertTrue($service->sync($this->admin, $this->server)['successful']);
        $ids = $this->server->worlds()->orderBy('world_identifier')->pluck('id')->all();
        $this->assertTrue($service->sync($this->admin, $this->server)['successful']);
        $this->assertSame($ids, $this->server->worlds()->orderBy('world_identifier')->pluck('id')->all());
        $this->assertDatabaseCount('luanti_worlds', 2);
        foreach ($this->server->worlds as $world) {
            $this->assertTrue($world->is_available);
            $this->assertTrue($world->is_selectable);
            $this->assertNotNull($world->last_discovered_at);
        }
        $this->assertNotNull($this->server->fresh()->worlds_synced_at);
        $this->assertNull($this->server->fresh()->world_sync_error);
        Http::assertSentCount(2);
    }

    public function test_sync_preserves_manual_names_hidden_flags_and_other_servers(): void
    {
        $existing = LuantiWorld::factory()->create(['luanti_server_id' => $this->server->id,
            'world_identifier' => 'dev', 'name' => 'Unser Kartenname', 'is_selectable' => false]);
        $manual = LuantiWorld::factory()->create(['luanti_server_id' => $this->server->id]);
        $otherServer = LuantiWorld::factory()->create(['world_identifier' => 'test']);
        Http::fake(['*' => Http::response($this->catalogue())]);
        app(LuantiWorldSynchronizer::class)->sync($this->admin, $this->server);
        $this->assertSame('Unser Kartenname', $existing->fresh()->name);
        $this->assertFalse($existing->fresh()->is_selectable);
        $this->assertNotNull($existing->fresh()->last_discovered_at);
        $this->assertTrue($manual->fresh()->is_available);
        $this->assertNull($manual->fresh()->last_discovered_at);
        $this->assertNull($otherServer->fresh()->last_discovered_at);
        $this->assertDatabaseCount('luanti_worlds', 4);
    }

    public function test_missing_maps_are_preserved_for_requests_and_reappear_without_changing_local_flags(): void
    {
        Http::fakeSequence()->push($this->catalogue())->push(['version' => 1, 'worlds' => []])->push($this->catalogue());
        $service = app(LuantiWorldSynchronizer::class);
        $service->sync($this->admin, $this->server);
        $world = $this->server->worlds()->where('world_identifier', 'dev')->sole();
        $request = WorldRequest::factory()->approved()->create(['luanti_world_id' => $world->id]);
        $membership = OrganizationMembership::factory()->create(['organization_id' => $request->organization_id, 'user_id' => $request->user_id]);
        $world->update(['is_selectable' => false]);
        $service->sync($this->admin, $this->server);
        $this->assertFalse($world->fresh()->is_available);
        $this->assertSame(WorldRequestStatus::Approved, $request->fresh()->status);
        $this->assertSame($world->id, $request->fresh()->luanti_world_id);
        $this->assertArrayNotHasKey($world->id, app(WorldRequests::class)->worldOptions($membership->user, $membership->organization));
        $this->assertArrayHasKey($world->id, app(WorldRequests::class)->worldOptions($membership->user, $membership->organization, $request));
        $service->sync($this->admin, $this->server);
        $this->assertTrue($world->fresh()->is_available);
        $this->assertFalse($world->fresh()->is_selectable);
        $this->assertDatabaseCount('luanti_worlds', 2);
    }

    public static function invalidResponses(): array
    {
        return [
            'bad JSON' => [200, '{broken'], 'HTML' => [200, '<html>private remote details</html>'],
            'wrong version' => [200, ['version' => 2, 'worlds' => []]],
            'string version' => [200, ['version' => '1', 'worlds' => []]],
            'boolean version' => [200, ['version' => true, 'worlds' => []]],
            'missing list' => [200, ['version' => 1]],
            'object list' => [200, '{"version":1,"worlds":{}}'],
            'invalid identifier' => [200, ['version' => 1, 'worlds' => [['world_id' => '../world', 'name' => 'Map']]]],
            'missing name' => [200, ['version' => 1, 'worlds' => [['world_id' => 'dev']]]],
            'blank name' => [200, ['version' => 1, 'worlds' => [['world_id' => 'dev', 'name' => ' ']]]],
            'duplicate ID' => [200, ['version' => 1, 'worlds' => [['world_id' => 'dev', 'name' => 'One'], ['world_id' => 'dev', 'name' => 'Two']]]],
            'partial malformed list' => [200, ['version' => 1, 'worlds' => [['world_id' => 'new', 'name' => 'One'], null]]],
            'oversized response' => [200, str_repeat('x', 256 * 1024 + 1)],
            'oversized unauthorized' => [401, str_repeat('x', 256 * 1024 + 1)],
            'oversized not found' => [404, str_repeat('x', 256 * 1024 + 1)],
            'oversized server error' => [500, str_repeat('x', 256 * 1024 + 1)],
            'unauthorized' => [401, ['private' => 'remote secret']],
            'endpoint absent' => [404, ['private' => 'remote secret']],
            'remote failure' => [503, ['private' => 'remote secret']],
            'redirect' => [302, 'private remote secret'],
        ];
    }

    #[DataProvider('invalidResponses')]
    public function test_failed_or_invalid_fetches_preserve_catalogue_and_last_success_and_use_safe_errors(int $status, array|string $body): void
    {
        $world = LuantiWorld::factory()->create(['luanti_server_id' => $this->server->id,
            'last_discovered_at' => now(), 'is_available' => true]);
        $this->server->forceFill(['worlds_synced_at' => now()->subHour()])->save();
        $syncedAt = $this->server->worlds_synced_at->toDateTimeString();
        $original = $world->fresh()->getRawOriginal();
        Http::fake(['*' => Http::response($body, $status, ['Location' => 'https://other.example.test'])]);
        $result = app(LuantiWorldSynchronizer::class)->sync($this->admin, $this->server);
        $this->assertFalse($result['successful']);
        $this->assertSame($original, $world->fresh()->getRawOriginal());
        $this->assertSame($syncedAt, $this->server->fresh()->worlds_synced_at->toDateTimeString());
        $this->assertNotNull($this->server->fresh()->world_sync_error);
        $this->assertStringNotContainsString('private', json_encode($result, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('private', $this->server->fresh()->world_sync_error);
        $this->assertDatabaseCount('luanti_worlds', 1);
        Http::assertSentCount(1);
    }

    public function test_connection_exception_is_sanitized_and_does_not_retry_or_clear_catalogue(): void
    {
        $world = LuantiWorld::factory()->create(['luanti_server_id' => $this->server->id]);
        Http::fake(['*' => Http::failedConnection('private-test-token')]);
        $result = app(LuantiWorldSynchronizer::class)->sync($this->admin, $this->server);
        $this->assertFalse($result['successful']);
        $this->assertSame('Bridge nicht erreichbar', $result['title']);
        $this->assertStringNotContainsString('private-test-token', json_encode($result, JSON_THROW_ON_ERROR));
        $this->assertModelExists($world);
        Http::assertSentCount(1);
    }

    public static function invalidSettings(): array
    {
        return [
            'missing URL' => ['api_base_url', null], 'missing token' => ['api_token', null],
            'plain HTTP' => ['api_base_url', 'http://bridge.example.test'],
            'URL credentials' => ['api_base_url', 'https://user:secret@bridge.example.test'],
            'URL query' => ['api_base_url', 'https://bridge.example.test?secret=value'],
            'URL fragment' => ['api_base_url', 'https://bridge.example.test#fragment'],
            'token newline' => ['api_token', "token\r\nInjected: value"],
        ];
    }

    #[DataProvider('invalidSettings')]
    public function test_invalid_configuration_never_sends_a_request(string $field, ?string $value): void
    {
        $this->server->update([$field => $value]);
        $this->assertFalse(app(LuantiWorldSynchronizer::class)->sync($this->admin, $this->server)['successful']);
        Http::assertNothingSent();
    }

    public static function deniedRoles(): array
    {
        return ['member' => ['member'], 'caseworker' => ['caseworker'], 'inactive' => ['inactive'], 'unverified' => ['unverified']];
    }

    #[DataProvider('deniedRoles')]
    public function test_only_current_active_verified_global_admins_can_trigger_manual_sync(string $role): void
    {
        $actor = match ($role) {
            'caseworker' => User::factory()->caseworker()->create(),
            'inactive' => User::factory()->globalAdmin()->inactive()->create(),
            'unverified' => User::factory()->globalAdmin()->unverified()->create(),
            default => User::factory()->create(),
        };
        try {
            app(LuantiWorldSynchronizer::class)->sync($actor, $this->server);
            $this->fail('Expected access denial.');
        } catch (AuthorizationException|HttpException) {
            Http::assertNothingSent();
        }
    }

    public function test_role_revocation_during_fetch_prevents_persistence(): void
    {
        Http::fake(['*' => function (): PromiseInterface {
            $this->admin->forceFill(['internal_role' => null])->save();

            return Http::response($this->catalogue());
        }]);
        $this->expectException(AuthorizationException::class);
        try {
            app(LuantiWorldSynchronizer::class)->sync($this->admin, $this->server);
        } finally {
            $this->assertDatabaseCount('luanti_worlds', 0);
            $this->assertNull($this->server->fresh()->worlds_synced_at);
        }
    }

    public function test_changed_api_configuration_during_fetch_discards_old_server_response(): void
    {
        Http::fake(['*' => function (): PromiseInterface {
            $this->server->update(['api_base_url' => 'https://replacement.example.test']);

            return Http::response($this->catalogue());
        }]);
        $result = app(LuantiWorldSynchronizer::class)->sync($this->admin, $this->server);
        $this->assertFalse($result['successful']);
        $this->assertDatabaseCount('luanti_worlds', 0);
    }

    public function test_manual_and_scheduled_sync_share_a_per_server_lock(): void
    {
        $lock = Cache::lock('luanti:worlds:sync:'.$this->server->id, 120);
        $this->assertTrue($lock->get());
        try {
            $result = app(LuantiWorldSynchronizer::class)->sync($this->admin, $this->server);
            $this->assertFalse($result['successful']);
            $this->assertSame('Synchronisierung läuft bereits', $result['title']);
            $this->assertFalse(app(LuantiWorldSynchronizer::class)->syncConfiguredServers()[$this->server->id]['successful']);
            Http::assertNothingSent();
        } finally {
            $lock->release();
        }
    }

    public function test_cli_sync_skips_unconfigured_servers_and_continues_after_failure(): void
    {
        LuantiServer::factory()->create();
        $other = LuantiServer::factory()->create(['api_base_url' => 'https://other.example.test', 'api_token' => 'other-token']);
        Http::fake(['https://bridge.example.test/prefix/v1/worlds' => Http::response('private error', 503),
            'https://other.example.test/v1/worlds' => Http::response($this->catalogue())]);
        $this->artisan('luanti:worlds:sync')->assertExitCode(1);
        $this->assertSame(0, $this->server->worlds()->count());
        $this->assertSame(2, $other->worlds()->count());
        Http::assertSentCount(2);
    }

    public function test_cli_can_sync_one_server_and_validates_target(): void
    {
        Http::fake(['*' => Http::response($this->catalogue())]);
        $this->artisan('luanti:worlds:sync', ['--server' => $this->server->id])->assertSuccessful();
        $this->artisan('luanti:worlds:sync', ['--server' => 'invalid'])->assertExitCode(1);
        $this->artisan('luanti:worlds:sync', ['--server' => 999999])->assertExitCode(1);
        Http::assertSentCount(1);
    }

    public function test_scheduler_registers_hourly_sync_without_overlap(): void
    {
        $events = collect(app(Schedule::class)->events())->filter(fn ($event): bool => str_contains($event->command ?? '', 'luanti:worlds:sync'));
        $this->assertCount(1, $events);
        $this->assertSame('0 * * * *', $events->first()->expression);
        $this->assertTrue($events->first()->withoutOverlapping);
    }

    public function test_admin_sync_actions_store_maps_and_make_them_selectable_in_request_form(): void
    {
        Http::fake(fn (): PromiseInterface => Http::response($this->catalogue()));
        $this->panel($this->admin);
        Livewire::test(LuantiServers::class)->callAction(TestAction::make('syncWorlds')->table($this->server))
            ->assertNotified('Welten synchronisiert')->assertDontSee('private-test-token');
        Livewire::test(LuantiWorlds::class)->callAction('syncWorlds', ['luanti_server_id' => $this->server->id])
            ->assertNotified('Welten synchronisiert')->assertCanSeeTableRecords($this->server->worlds);
        $membership = OrganizationMembership::factory()->create();
        $this->panel($membership->user, 'portal', $membership->organization);
        $world = $this->server->worlds()->where('world_identifier', 'dev')->sole();
        Livewire::test(WorldRequestsPage::class)->callAction('create', [
            'name' => 'Kartenantrag', 'luanti_world_id' => $world->id, 'start_date' => now('Europe/Berlin')->toDateString(),
            'end_date' => now('Europe/Berlin')->toDateString(), 'participant_count' => 12,
        ])->assertHasNoActionErrors()->assertNotified('Weltantrag eingereicht');
        $this->assertDatabaseHas('world_requests', ['luanti_world_id' => $world->id]);
    }

    public function test_synced_map_name_and_hidden_setting_remain_editable_but_identity_is_protected(): void
    {
        Http::fake(fn (): PromiseInterface => Http::response($this->catalogue()));
        app(LuantiWorldSynchronizer::class)->sync($this->admin, $this->server);
        $world = $this->server->worlds()->where('world_identifier', 'dev')->sole();
        $this->panel($this->admin);
        Livewire::test(LuantiWorlds::class)->callAction(TestAction::make('edit')->table($world), [
            'name' => 'Lokaler Name', 'is_selectable' => false,
        ])->assertHasNoActionErrors();
        app(LuantiWorldSynchronizer::class)->sync($this->admin, $this->server);
        $this->assertSame('Lokaler Name', $world->fresh()->name);
        $this->assertFalse($world->fresh()->is_selectable);
        $this->expectException(ValidationException::class);
        app(LuantiWorldRegistry::class)->update($this->admin, $world, [
            ...$world->only(['luanti_server_id', 'name', 'world_identifier', 'is_selectable']), 'world_identifier' => 'replacement',
        ]);
    }

    public function test_unavailable_maps_cannot_be_requested_or_approved_but_existing_requests_allow_corrections(): void
    {
        $world = LuantiWorld::factory()->create(['is_available' => false]);
        $request = WorldRequest::factory()->create(['luanti_world_id' => $world->id]);
        $membership = OrganizationMembership::factory()->create(['organization_id' => $request->organization_id, 'user_id' => $request->user_id]);
        $data = [...$request->only(['name', 'luanti_world_id', 'participant_count']),
            'start_date' => $request->start_date->toDateString(), 'end_date' => $request->end_date->toDateString()];
        $service = app(WorldRequests::class);
        $service->update($membership->user, $request, [...$data, 'participant_count' => 20], 1);
        try {
            $service->create($membership->user, $membership->organization, $data);
            $this->fail('Expected unavailable map validation.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('luanti_world_id', $exception->errors());
        }
        $this->assertSame(20, $request->fresh()->participant_count);
        $this->expectException(ValidationException::class);
        $service->decide($this->admin, $request, true, 2);
    }

    public function test_sync_failure_is_shown_in_admin_ui_without_remote_content(): void
    {
        Http::fake(['*' => Http::response('private upstream error', 500)]);
        $this->panel($this->admin);
        Livewire::test(LuantiServers::class)->callAction(TestAction::make('syncWorlds')->table($this->server))
            ->assertNotified('Weltliste konnte nicht geladen werden')->assertDontSee('private upstream error')->assertDontSee('private-test-token');
        $this->assertNotNull($this->server->fresh()->world_sync_error);
    }
}
