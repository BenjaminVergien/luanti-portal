<?php

namespace Tests\Feature;

use App\Filament\Pages\LuantiWorldTemplates;
use App\Models\LuantiServer;
use App\Models\LuantiWorldTemplate;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Models\WorldRequest;
use App\Services\BridgeProvisioning;
use App\Services\GithubBridgeSource;
use App\Services\HetznerVirtualMachines;
use App\Services\LuantiImageCatalog;
use App\Services\LuantiServerRegistry;
use App\Services\LuantiSshClient;
use App\Services\LuantiWorldTemplateRegistry;
use App\Services\PortalSettings;
use App\Services\PortalWorldTemplates;
use App\Services\WorldRequests;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use ZipArchive;

class PortalWorldTemplatesTest extends TestCase
{
    use RefreshDatabase;

    private array $distributionArtifacts = [];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::set('local', Storage::fake('wt'.bin2hex(random_bytes(2))));
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        foreach ($this->distributionArtifacts as $digest) {
            File::delete(storage_path('app/private/bridge-distribution/'.$digest.'.zip'));
        }
        parent::tearDown();
    }

    private function archive(string $identifier = 'dev', string $name = 'Dev', array $extra = [], bool $badRevision = false): string
    {
        $directory = Storage::disk('local')->path('fixtures');
        File::ensureDirectoryExists($directory);
        $databasePath = $directory.'/'.bin2hex(random_bytes(8)).'.sqlite';
        $database = new PDO('sqlite:'.$databasePath);
        $database->exec('CREATE TABLE blocks (pos INTEGER PRIMARY KEY, data BLOB)');
        $database = null;
        $files = ['world.mt' => "gameid = minetest\nload_mod_civicrm_bridge = mods/civicrm_bridge\n", 'map.sqlite' => File::get($databasePath), ...$extra];
        $hashes = array_map(fn (string $bytes): string => hash('sha256', $bytes), $files);
        ksort($hashes);
        $content = ['files' => $hashes, 'name' => $name, 'template_id' => $identifier];
        $manifest = ['version' => 1, ...$content, 'revision' => $badRevision ? str_repeat('f', 64) : hash('sha256', json_encode($content, JSON_UNESCAPED_SLASHES))];
        $path = $directory.'/'.bin2hex(random_bytes(8)).'.zip';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE);
        foreach ([...$files, 'manifest.json' => json_encode($manifest)] as $filename => $bytes) {
            $zip->addFromString($filename, $bytes);
        }
        $zip->close();

        return $path;
    }

    public function test_import_retains_validated_private_bundles_and_all_templates_without_a_server(): void
    {
        $actor = User::factory()->globalAdmin()->create();
        $library = app(PortalWorldTemplates::class);
        $path = $this->archive();
        $template = $library->import($actor, $path);
        $other = $library->import($actor, $this->archive('test', 'Täst / world'));
        $other->update(['is_selectable' => false]);
        $this->assertNull($template->luanti_server_id);
        $this->assertSame('dev', $template->library_identifier);
        $this->assertTrue($template->is_available);
        $this->assertFileEquals($path, $library->artifact($template->bundle_sha256));
        $this->assertCount(2, $library->snapshot());
        $this->assertFalse($library->snapshot()[1]['is_selectable']);
        $library->import($actor, $path);
        $this->assertDatabaseCount('luanti_world_templates', 2);
        $this->assertDatabaseCount('luanti_servers', 0);
        Http::assertNothingSent();
    }

    public function test_new_library_revision_preserves_pinned_server_copies_and_previous_archives(): void
    {
        $actor = User::factory()->globalAdmin()->create();
        $library = app(PortalWorldTemplates::class);
        $template = $library->import($actor, $this->archive());
        $oldDigest = $template->bundle_sha256;
        $snapshot = $library->snapshot();
        $server = LuantiServer::factory()->create();
        $library->registerCommissioned($server, $snapshot);
        $copy = $server->templates()->sole();
        $library->import($actor, $this->archive(name: 'New revision'));
        $this->assertNotSame($template->revision, $template->fresh()->revision);
        $this->assertSame($snapshot[0]['revision'], $copy->fresh()->revision);
        $this->assertFileExists($library->artifact($oldDigest));
        $this->assertSame($oldDigest, $copy->bundle_sha256);
    }

    public function test_vm_deletion_preserves_the_portal_library_and_its_private_template_zip(): void
    {
        config(['services.hetzner.token' => 'project-secret']);
        $actor = User::factory()->globalAdmin()->create();
        $library = app(PortalWorldTemplates::class);
        $archive = $this->archive();
        $template = $library->import($actor, $archive);
        $server = LuantiServer::factory()->hetznerVm()->create();
        $library->registerCommissioned($server, $library->snapshot());
        Http::fake(['https://api.hetzner.cloud/v1/servers/42' => Http::response([], 404)]);

        app(HetznerVirtualMachines::class)->delete($actor, $server, $server->name);

        $this->assertSame('deleted', $server->fresh()->hetzner_status);
        $copy = $server->templates()->sole();
        $this->assertFalse($copy->is_available);
        app(LuantiServerRegistry::class)->delete($actor, $server);
        $this->assertModelMissing($server);
        $this->assertModelMissing($copy);
        $this->assertTrue($template->fresh()->is_available);
        $this->assertFileEquals($archive, $library->artifact($template->bundle_sha256));
        $this->assertCount(1, $library->snapshot());
        Http::assertSentCount(1);
    }

    #[DataProvider('invalidBundles')]
    public function test_import_rejects_unsafe_or_modified_bundles(array $extra, bool $badRevision): void
    {
        $path = $this->archive(extra: $extra, badRevision: $badRevision);
        $this->expectException(ValidationException::class);
        app(PortalWorldTemplates::class)->import(User::factory()->globalAdmin()->create(), $path);
    }

    public static function invalidBundles(): array
    {
        return [
            'accounts' => [['auth.sqlite' => 'private account data'], false],
            'traversal' => [['../outside' => 'unsafe'], false],
            'players' => [['players/name' => 'private progress'], false],
            'revision mismatch' => [[], true],
            'invalid sqlite' => [['map.sqlite' => 'not a database'], false],
            'wrong game' => [['world.mt' => "gameid = other\nload_mod_civicrm_bridge = mods/civicrm_bridge\n"], false],
            'external backend' => [['world.mt' => "gameid = minetest\nload_mod_civicrm_bridge = mods/civicrm_bridge\nauth_backend = postgresql\n"], false],
        ];
    }

    public function test_missing_or_modified_portal_archive_blocks_server_preparation(): void
    {
        $library = app(PortalWorldTemplates::class);
        $template = $library->import(User::factory()->globalAdmin()->create(), $this->archive());
        File::put($library->artifact($template->bundle_sha256), 'modified');
        $this->expectException(ValidationException::class);
        $library->snapshot();
    }

    public function test_library_management_is_admin_only_but_members_can_request_templates_without_a_server(): void
    {
        $library = app(PortalWorldTemplates::class);
        $path = $this->archive();
        try {
            $library->import(User::factory()->caseworker()->create(), $path);
            $this->fail('Expected authorization failure');
        } catch (AuthorizationException) {
            $this->assertDatabaseCount('luanti_world_templates', 0);
        }
        $source = $library->import(User::factory()->globalAdmin()->create(), $path);
        $request = WorldRequest::factory()->create();
        OrganizationMembership::factory()->create(['organization_id' => $request->organization_id, 'user_id' => $request->user_id]);
        $this->assertArrayHasKey($source->id, app(WorldRequests::class)->worldOptions($request->applicant, $request->organization));
        $submitted = app(WorldRequests::class)->create($request->applicant, $request->organization,
            [...$request->only(['name', 'participant_count']), 'start_date' => $request->start_date->toDateString(),
                'end_date' => $request->end_date->toDateString(), 'luanti_world_template_id' => $source->id]);
        $this->assertSame($source->revision, $submitted->template_snapshot['revision']);
        $this->assertNull($submitted->world->luanti_server_id);
        Http::assertNothingSent();
    }

    public function test_admin_can_upload_and_edit_library_metadata_without_changing_its_identity(): void
    {
        $actor = User::factory()->globalAdmin()->create();
        $this->actingAs($actor);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::bootCurrentPanel();
        $upload = UploadedFile::fake()->createWithContent('template.zip', File::get($this->archive()));
        $page = Livewire::test(LuantiWorldTemplates::class)->callAction('import', ['bundle' => $upload])
            ->assertHasNoActionErrors()->assertNotified('Vorlage im Portal gespeichert');
        $template = LuantiWorldTemplate::query()->sole();
        $page->callAction(TestAction::make('edit')->table($template), ['name' => 'Renamed',
            'description' => 'Eine Bauwelt für gemeinsames Planen und Gestalten.', 'is_selectable' => false])
            ->assertHasNoActionErrors()->assertNotified('Gespeichert');
        $this->assertSame('Renamed', $template->fresh()->name);
        $this->assertFalse($template->fresh()->is_selectable);
        $this->assertSame('Eine Bauwelt für gemeinsames Planen und Gestalten.', $template->fresh()->description);
        $page->mountAction(TestAction::make('edit')->table($template))
            ->assertActionDataSet(['description' => 'Eine Bauwelt für gemeinsames Planen und Gestalten.']);
        app(PortalWorldTemplates::class)->import($actor, $this->archive());
        $this->assertSame('Eine Bauwelt für gemeinsames Planen und Gestalten.', $template->fresh()->description);
        $this->expectException(ValidationException::class);
        app(LuantiWorldTemplateRegistry::class)->update($actor, $template, ['luanti_server_id' => null,
            'name' => 'Renamed', 'template_identifier' => 'replacement', 'is_selectable' => true]);
    }

    public function test_library_descriptions_are_copied_to_servers_and_updates_preserve_the_game_revision(): void
    {
        $actor = User::factory()->globalAdmin()->create();
        $library = app(PortalWorldTemplates::class);
        $template = $library->import($actor, $this->archive());
        $originalRevision = $template->revision;
        $originalBundle = $template->bundle_sha256;
        $server = LuantiServer::factory()->create();
        $library->registerCommissioned($server, $library->snapshot());
        $attributes = $template->only(['name', 'template_identifier', 'is_selectable']);
        app(LuantiWorldTemplateRegistry::class)->update($actor, $template, [...$attributes, 'description' => '  Eine offene Bauwelt.  ']);

        $this->assertSame('Eine offene Bauwelt.', $template->fresh()->description);
        $this->assertSame('Eine offene Bauwelt.', $server->templates()->sole()->description);
        $second = LuantiServer::factory()->create();
        $library->registerCommissioned($second, $library->snapshot());
        $this->assertSame('Eine offene Bauwelt.', $second->templates()->sole()->description);
        $this->assertSame($originalRevision, $template->fresh()->revision);
        $this->assertSame($originalBundle, $template->fresh()->bundle_sha256);

        app(LuantiWorldTemplateRegistry::class)->update($actor, $template, [...$attributes, 'description' => '   ']);
        $this->assertNull($template->fresh()->description);
        $this->assertNull($second->templates()->sole()->description);
    }

    public function test_template_description_validation_preserves_existing_text_on_invalid_input(): void
    {
        $actor = User::factory()->globalAdmin()->create();
        $template = app(PortalWorldTemplates::class)->import($actor, $this->archive());
        $template->update(['description' => 'Vorhandene Beschreibung']);
        $attributes = $template->only(['name', 'template_identifier', 'is_selectable']);
        foreach ([str_repeat('x', 5001), ['unexpected' => 'array']] as $description) {
            try {
                app(LuantiWorldTemplateRegistry::class)->update($actor, $template, [...$attributes, 'description' => $description]);
                $this->fail('Invalid description was saved.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('description', $exception->errors());
            }
        }
        $this->assertSame('Vorhandene Beschreibung', $template->fresh()->description);
        app(LuantiWorldTemplateRegistry::class)->update($actor, $template, $attributes);
        $this->assertSame('Vorhandene Beschreibung', $template->fresh()->description);
    }

    public function test_server_installation_commissions_the_entire_creation_snapshot_and_requires_confirmation(): void
    {
        $actor = User::factory()->globalAdmin()->create();
        $library = app(PortalWorldTemplates::class);
        $library->import($actor, $this->archive());
        $library->import($actor, $this->archive('test', 'Test'));
        $snapshot = $library->snapshot();
        Cache::forever(LuantiImageCatalog::CacheKey, ['images' => ['ghcr.io/luanti-org/luanti:5.17.0' => '5.17.0'], 'fetched_at' => now()->timestamp]);
        app(PortalSettings::class)->save($actor, ['bridge_enabled' => true, 'luanti_docker_image' => 'ghcr.io/luanti-org/luanti:5.17.0']);
        $bytes = 'test-source-'.bin2hex(random_bytes(8));
        $game = 'test-game-'.bin2hex(random_bytes(8));
        $this->distributionArtifacts = [hash('sha256', $bytes), hash('sha256', $game)];
        $this->mock(GithubBridgeSource::class)->shouldReceive('game')->once()->andReturn($game)
            ->shouldReceive('resolve')->once()->andReturn(str_repeat('a', 40))
            ->shouldReceive('bundle')->once()->andReturn(['bytes' => $bytes, 'source_sha256' => str_repeat('b', 64)]);
        $prepared = app(BridgeProvisioning::class)->prepare($actor);
        $this->assertSame($snapshot, $prepared['configuration']['templates']);
        $library->import($actor, $this->archive(name: 'Changed after creation'));
        $server = LuantiServer::factory()->hetznerVm()->create(['bridge_configuration' => $prepared['configuration'],
            'bridge_commit' => $prepared['commit'], 'bridge_status' => 'pending', 'bridge_requested_by' => $actor->id,
            'luanti_installation_status' => 'verified', 'luanti_image_id' => 'sha256:'.str_repeat('c', 64)]);
        $this->mock(HetznerVirtualMachines::class)->shouldReceive('refresh')->twice()->andReturn($server);
        $options = ['private_key_bits' => 2048];
        if (is_file(dirname(PHP_BINARY).'/extras/ssl/openssl.cnf')) {
            $options['config'] = dirname(PHP_BINARY).'/extras/ssl/openssl.cnf';
        }
        $key = openssl_pkey_new($options);
        $csr = openssl_csr_new(['commonName' => 'fictitious-test-ca'], $key, $options);
        openssl_x509_export(openssl_csr_sign($csr, null, $key, 1, $options), $certificate);
        $response = ['ok' => true, 'commit' => $server->bridge_commit, 'source_sha256' => str_repeat('b', 64),
            'image_id' => $server->luanti_image_id, 'token' => str_repeat('t', 43), 'ca_certificate' => $certificate];
        $expected = array_column($snapshot, 'revision', 'template_id');
        $this->mock(LuantiSshClient::class)->shouldReceive('execute')->twice()->withArgs(function (LuantiServer $vm, string $script) use ($expected): bool {
            preg_match('/b64decode\("([A-Za-z0-9+\/=]+)"\)/', $script, $matches);
            $payload = json_decode(base64_decode($matches[1]), true);
            $this->assertSame($expected, array_map(fn (array $entry): string => $entry['revision'], $payload['templates']));
            foreach ($payload['templates'] as $entry) {
                $this->assertSame($entry['sha256'], hash('sha256', base64_decode($entry['archive'])));
            }

            return true;
        })->andReturn(json_encode($response), json_encode([...$response, 'templates' => $expected]));
        $this->assertFalse(app(BridgeProvisioning::class)->process($server->id));
        $this->assertSame('failed', $server->fresh()->bridge_status);
        $this->assertSame(0, $server->templates()->count());
        $server->forceFill(['bridge_status' => 'pending'])->save();
        $this->assertFalse(app(BridgeProvisioning::class)->process($server->id));
        $this->assertSame('installed', $server->fresh()->bridge_status);
        $this->assertSame($expected, $server->templates()->orderBy('template_identifier')->pluck('revision', 'template_identifier')->all());
        $this->assertSame($snapshot[0]['revision'], $server->templates()->where('template_identifier', 'dev')->sole()->revision);
    }

    public function test_import_command_requires_a_global_admin_and_adopts_matching_legacy_templates(): void
    {
        $actor = User::factory()->globalAdmin()->create();
        $path = $this->archive();
        $this->artisan('luanti:templates:import', ['archive' => $path])->assertFailed();
        $this->artisan('luanti:templates:import', ['archive' => $path, '--actor' => $actor->id])->assertSuccessful();
        $source = LuantiWorldTemplate::query()->whereNotNull('library_identifier')->sole();
        $legacy = LuantiWorldTemplate::factory()->create(['template_identifier' => $source->template_identifier, 'revision' => $source->revision]);
        $this->artisan('luanti:templates:import', ['archive' => $path, '--actor' => $actor->id])->assertSuccessful();
        $this->assertSame($source->bundle_sha256, $legacy->fresh()->bundle_sha256);
    }
}
