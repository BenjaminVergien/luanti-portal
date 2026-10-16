<?php

namespace Tests\Feature;

use App\Filament\Pages\LuantiServers;
use App\Filament\Pages\LuantiWorldTemplates;
use App\Models\LuantiServer;
use App\Models\LuantiWorldTemplate;
use App\Models\User;
use App\Models\WorldRequest;
use App\Services\LuantiServerRegistry;
use App\Services\LuantiWorldTemplateRegistry;
use Database\Seeders\LuantiWorldTemplateSeeder;
use Database\Seeders\WorldRequestSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class LuantiWorldTemplatesTest extends TestCase
{
    use RefreshDatabase;

    private function panel(User $user): void
    {
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::bootCurrentPanel();
        Filament::setTenant(null);
    }

    public function test_admin_creates_lists_searches_and_retires_worlds_without_bridge_calls(): void
    {
        Http::preventStrayRequests();
        $this->panel(User::factory()->globalAdmin()->create());
        $server = LuantiServer::factory()->create(['api_token' => 'private-token']);
        $this->get('/admin/welten')->assertOk();
        $page = Livewire::test(LuantiWorldTemplates::class)->callAction('create', [
            'luanti_server_id' => $server->id, 'name' => ' Jugendwelt ', 'template_identifier' => ' youth ', 'is_selectable' => true,
        ])->assertHasNoActionErrors()->assertNotified('Welt angelegt')->assertDontSee('private-token');
        $world = LuantiWorldTemplate::query()->sole();
        $this->assertSame('Jugendwelt', $world->name);
        $this->assertSame('youth', $world->template_identifier);
        $this->assertTrue($world->is_selectable);
        $other = LuantiWorldTemplate::factory()->create(['name' => 'Andere Welt']);
        $page->searchTable('Jugendwelt')->assertCanSeeTableRecords([$world])->assertCanNotSeeTableRecords([$other]);
        $page->callAction(TestAction::make('edit')->table($world), ['is_selectable' => false])
            ->assertHasNoActionErrors()->assertNotified('Gespeichert');
        $this->assertFalse($world->fresh()->is_selectable);
        Http::assertNothingSent();
    }

    public function test_template_identifiers_are_unique_per_server_but_reusable_on_other_servers(): void
    {
        $admin = User::factory()->globalAdmin()->create();
        $registry = app(LuantiWorldTemplateRegistry::class);
        $world = LuantiWorldTemplate::factory()->create(['template_identifier' => 'dev']);
        $data = $world->only(['luanti_server_id', 'name', 'template_identifier', 'is_selectable']);
        $registry->create($admin, [...$data, 'luanti_server_id' => LuantiServer::factory()->create()->id]);
        try {
            $registry->create($admin, $data);
            $this->fail('Expected duplicate validation failure.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('template_identifier', $exception->errors());
        }
        $this->assertDatabaseCount('luanti_world_templates', 2);
    }

    public function test_catalogue_rejects_invalid_names_identifiers_servers_and_flags(): void
    {
        $this->panel(User::factory()->globalAdmin()->create());
        Livewire::test(LuantiWorldTemplates::class)->callAction('create')->assertHasActionErrors([
            'name' => 'required', 'luanti_server_id' => 'required', 'template_identifier' => 'required',
        ]);
        $this->assertDatabaseCount('luanti_world_templates', 0);
        $this->expectException(ValidationException::class);
        app(LuantiWorldTemplateRegistry::class)->create(auth()->user(), ['luanti_server_id' => 999999,
            'name' => ' ', 'template_identifier' => '', 'is_selectable' => 'invalid']);
    }

    public function test_caseworkers_and_members_cannot_access_or_modify_catalogue(): void
    {
        foreach ([User::factory()->caseworker()->create(), User::factory()->create()] as $actor) {
            $this->panel($actor);
            $this->get('/admin/welten')->assertForbidden();
            try {
                app(LuantiWorldTemplateRegistry::class)->create($actor, []);
                $this->fail('Expected authorization failure.');
            } catch (AuthorizationException $exception) {
                $this->assertDatabaseCount('luanti_world_templates', 0);
            }
        }
    }

    public function test_open_catalogue_refuses_access_after_admin_role_is_removed(): void
    {
        $admin = User::factory()->globalAdmin()->create();
        $this->panel($admin);
        $world = LuantiWorldTemplate::factory()->create();
        $page = Livewire::test(LuantiWorldTemplates::class)->mountAction(TestAction::make('edit')->table($world))->fillForm(['name' => 'Unauthorized']);
        $admin->forceFill(['internal_role' => null])->save();
        $page->call('callMountedAction')->assertForbidden();
        $this->assertNotSame('Unauthorized', $world->fresh()->name);
    }

    public function test_referenced_world_identity_cannot_change_but_name_and_selectability_can(): void
    {
        $world = LuantiWorldTemplate::factory()->create();
        $request = WorldRequest::factory()->create(['luanti_world_template_id' => $world->id]);
        $admin = User::factory()->globalAdmin()->create();
        $data = $world->only(['luanti_server_id', 'name', 'template_identifier', 'is_selectable']);
        app(LuantiWorldTemplateRegistry::class)->update($admin, $world, [...$data, 'name' => 'Updated', 'is_selectable' => false]);
        $this->assertSame($world->id, $request->fresh()->luanti_world_template_id);
        $this->assertFalse($world->fresh()->is_selectable);
        foreach (['template_identifier' => 'replacement', 'luanti_server_id' => LuantiServer::factory()->create()->id] as $field => $value) {
            try {
                app(LuantiWorldTemplateRegistry::class)->update($admin, $world, [...$data, $field => $value]);
                $this->fail('Expected identity protection.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('template_identifier', $exception->errors());
            }
        }
    }

    public function test_servers_with_catalogue_worlds_cannot_be_deleted_and_ui_explains_why(): void
    {
        $world = LuantiWorldTemplate::factory()->create();
        $admin = User::factory()->globalAdmin()->create();
        $this->panel($admin);
        Livewire::test(LuantiServers::class)->callAction(TestAction::make('delete')->table($world->server))
            ->assertNotified('Server kann nicht gelöscht werden');
        $this->assertModelExists($world->server);
        $this->expectException(ValidationException::class);
        app(LuantiServerRegistry::class)->delete($admin, $world->server);
    }

    public function test_database_preserves_worlds_referenced_by_requests(): void
    {
        $request = WorldRequest::factory()->create();
        $this->expectException(QueryException::class);
        $request->world->delete();
    }

    public function test_optional_example_seeders_are_idempotent_and_never_run_in_production(): void
    {
        $this->app->detectEnvironment(fn (): string => 'local');
        $this->seed(LuantiWorldTemplateSeeder::class);
        $this->seed(LuantiWorldTemplateSeeder::class);
        $this->seed(WorldRequestSeeder::class);
        $this->assertDatabaseCount('luanti_world_templates', 1);
        $this->assertDatabaseCount('luanti_servers', 1);
        $this->assertDatabaseCount('world_requests', 0);
        $this->app->detectEnvironment(fn (): string => 'production');
        $this->expectException(\RuntimeException::class);
        $this->app->make(LuantiWorldTemplateSeeder::class)->run();
    }
}
