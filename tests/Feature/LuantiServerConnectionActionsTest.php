<?php

namespace Tests\Feature;

use App\Enums\InternalRole;
use App\Filament\Pages\LuantiServers;
use App\Models\LuantiServer;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LuantiServerConnectionActionsTest extends TestCase
{
    use RefreshDatabase;

    private function adminPanel(User $user): void
    {
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::bootCurrentPanel();
        Filament::setTenant(null);
    }

    public function test_create_dialog_checks_unsaved_connection_fields_without_validating_other_fields_or_saving(): void
    {
        $this->adminPanel(User::factory()->globalAdmin()->create());
        Http::preventStrayRequests();
        Http::fake(['https://bridge.example.test/v1/lifecycle/jobs/connection-check-*' => Http::response(['version' => 1, 'error' => ['code' => 'unknown_job']], 404)]);

        $page = Livewire::test(LuantiServers::class)->mountAction('create')
            ->fillForm(['api_base_url' => 'https://bridge.example.test', 'api_token' => 'entered-token'])
            ->callAction(TestAction::make('checkConnection')->schemaComponent('connectionCheck'))
            ->assertNotified('API-Verbindung erfolgreich')->assertActionMounted('create')
            ->assertActionDataSet(['api_base_url' => 'https://bridge.example.test', 'api_token' => 'entered-token']);

        $this->assertCount(1, $page->instance()->mountedActions);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer entered-token'));
        $this->assertDatabaseCount('luanti_servers', 0);
    }

    /** @return array<string, array{string, string}> */
    public static function editTokens(): array
    {
        return ['blank uses saved token' => ['', 'stored-secret'], 'replacement uses input' => ['replacement-token', 'replacement-token']];
    }

    #[DataProvider('editTokens')]
    public function test_edit_dialog_checks_unsaved_url_and_effective_token_without_changing_saved_settings(string $inputToken, string $expectedToken): void
    {
        $this->adminPanel(User::factory()->globalAdmin()->create());
        $server = LuantiServer::factory()->create(['api_base_url' => 'https://old.example.test', 'api_token' => 'stored-secret']);
        $attributes = $server->fresh()->getRawOriginal();
        Http::preventStrayRequests();
        Http::fake(['https://new.example.test/v1/lifecycle/jobs/connection-check-*' => Http::response(['version' => 1, 'error' => ['code' => 'unknown_job']], 404)]);

        $page = Livewire::test(LuantiServers::class)->mountAction(TestAction::make('edit')->table($server))
            ->fillForm(['name' => 'Unsaved name', 'api_base_url' => 'https://new.example.test', 'api_token' => $inputToken])
            ->callAction(TestAction::make('checkConnection')->schemaComponent('connectionCheck'))
            ->assertNotified('API-Verbindung erfolgreich')->assertActionMounted(TestAction::make('edit')->table($server))
            ->assertActionDataSet(['name' => 'Unsaved name', 'api_base_url' => 'https://new.example.test', 'api_token' => $inputToken]);

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer '.$expectedToken));
        $this->assertSame($attributes, $server->fresh()->getRawOriginal());
        $payload = json_encode([$page->snapshot, $page->effects], JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('stored-secret', $payload);
        $this->assertStringNotContainsString($attributes['api_token'], $payload);
    }

    public function test_saved_row_checks_saved_configuration_and_keeps_tokens_out_of_livewire_response(): void
    {
        $this->adminPanel(User::factory()->globalAdmin()->create());
        $server = LuantiServer::factory()->create(['api_base_url' => 'https://bridge.example.test', 'api_token' => 'stored-secret']);
        $attributes = $server->fresh()->getRawOriginal();
        Http::preventStrayRequests();
        Http::fake(['https://bridge.example.test/v1/lifecycle/jobs/connection-check-*' => Http::response(['version' => 1, 'error' => ['code' => 'unknown_job']], 404)]);

        $page = Livewire::test(LuantiServers::class)->callAction(TestAction::make('checkConnection')->table($server))
            ->assertNotified('API-Verbindung erfolgreich')->assertActionNotMounted();

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer stored-secret'));
        $this->assertSame($attributes, $server->fresh()->getRawOriginal());
        $payload = json_encode([$page->snapshot, $page->effects], JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('stored-secret', $payload);
        $this->assertStringNotContainsString($attributes['api_token'], $payload);
    }

    public function test_cleared_unsaved_url_does_not_fall_back_to_saved_url_and_failure_does_not_block_saving(): void
    {
        $this->adminPanel(User::factory()->globalAdmin()->create());
        $server = LuantiServer::factory()->create(['api_base_url' => 'https://bridge.example.test', 'api_token' => 'stored-secret']);
        Http::preventStrayRequests();

        Livewire::test(LuantiServers::class)->mountAction(TestAction::make('edit')->table($server))
            ->fillForm(['api_base_url' => '', 'name' => 'Changed name'])
            ->callAction(TestAction::make('checkConnection')->schemaComponent('connectionCheck'))
            ->assertNotified('API-Konfiguration fehlt')->assertActionMounted(TestAction::make('edit')->table($server))
            ->callMountedAction()->assertHasNoActionErrors()->assertNotified('Gespeichert');

        Http::assertNothingSent();
        $this->assertDatabaseHas('luanti_servers', ['id' => $server->id, 'name' => 'Changed name', 'api_base_url' => null]);
        $this->assertSame('stored-secret', $server->fresh()->api_token);
    }

    public function test_failed_check_keeps_dialog_open_and_does_not_expose_raw_api_response(): void
    {
        $this->adminPanel(User::factory()->globalAdmin()->create());
        Http::preventStrayRequests();
        Http::fake(['https://bridge.example.test/v1/lifecycle/jobs/connection-check-*' => Http::response('upstream-private-information', 500)]);

        $page = Livewire::test(LuantiServers::class)->mountAction('create')
            ->fillForm(['api_base_url' => 'https://bridge.example.test', 'api_token' => 'entered-token'])
            ->callAction(TestAction::make('checkConnection')->schemaComponent('connectionCheck'))
            ->assertNotified('API-Fehler')->assertActionMounted('create');

        $this->assertStringNotContainsString('upstream-private-information', json_encode([$page->snapshot, $page->effects], JSON_THROW_ON_ERROR));
        Http::assertSentCount(1);
        $this->assertDatabaseCount('luanti_servers', 0);
    }

    /** @return array<string, array{string, string}> */
    public static function revokedChecks(): array
    {
        $cases = [];
        foreach (['demoted', 'inactive', 'unverified'] as $change) {
            foreach (['row', 'create', 'edit'] as $location) {
                $cases[$change.' during '.$location] = [$change, $location];
            }
        }

        return $cases;
    }

    #[DataProvider('revokedChecks')]
    public function test_open_page_refuses_connection_checks_after_access_is_revoked(string $change, string $location): void
    {
        $admin = User::factory()->globalAdmin()->create();
        $this->adminPanel($admin);
        $server = LuantiServer::factory()->create(['api_base_url' => 'https://bridge.example.test', 'api_token' => 'stored-secret']);
        $attributes = $server->fresh()->getRawOriginal();
        $page = Livewire::test(LuantiServers::class);
        if ($location !== 'row') {
            $page->mountAction($location === 'create' ? 'create' : TestAction::make('edit')->table($server))
                ->fillForm(['api_base_url' => 'https://bridge.example.test', 'api_token' => 'entered-token']);
        }
        $admin->forceFill(match ($change) {
            'demoted' => ['internal_role' => InternalRole::Caseworker],
            'inactive' => ['is_active' => false],
            'unverified' => ['email_verified_at' => null],
        })->save();
        Http::preventStrayRequests();

        $page->call('mountAction', 'checkConnection', [], $location === 'row'
            ? ['table' => true, 'recordKey' => $server->id]
            : ['schemaComponent' => 'mountedActionSchema0.connectionCheck'])->assertForbidden();

        Http::assertNothingSent();
        $this->assertSame($attributes, $server->fresh()->getRawOriginal());
    }
}
