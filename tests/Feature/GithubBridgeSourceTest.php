<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\GithubBridgeSource;
use App\Services\PortalSettings;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;
use ZipArchive;

class GithubBridgeSourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_streamed_github_response_stays_readable_after_temporary_sink_closes_and_enforces_download_bound(): void
    {
        $handler = function ($request, array $options): mixed {
            $this->assertFalse($options['allow_redirects']);
            $this->assertTrue($options['verify']);
            $this->assertSame('application/vnd.github.sha', $request->getHeaderLine('Accept'));
            $options['sink']->write(str_repeat('a', 40)."\n");

            return Create::promiseFor(new Response(200, [], $options['sink']));
        };
        $source = new class(app(PortalSettings::class), $handler) extends GithubBridgeSource
        {
            public function __construct(PortalSettings $settings, private mixed $handler)
            {
                parent::__construct($settings);
            }

            protected function transportHandler(): callable
            {
                return $this->handler;
            }
        };
        $this->assertSame(str_repeat('a', 40), $source->resolve('Owner/Bridge', 'main'));
        $large = new class(app(PortalSettings::class)) extends GithubBridgeSource
        {
            protected function transportHandler(): callable
            {
                return function ($request, array $options): mixed {
                    $options['sink']->write(str_repeat('x', 262145));

                    return Create::promiseFor(new Response(200));
                };
            }
        };
        $this->expectException(ValidationException::class);
        $large->resolve('Owner/Bridge', 'main');
    }

    private function archive(array $extra = []): string
    {
        $files = array_fill_keys(['init.lua', 'bridge.lua', 'mod.conf', 'api/serve.py', 'api/development_proxy.py', 'api/requirements.txt', 'lifecycle/install_docker.py', 'tools/deploy_development.py'], 'reviewed source');
        $path = tempnam(sys_get_temp_dir(), 'bridge-test');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        foreach ([...$files, ...$extra] as $name => $content) {
            $zip->addFromString('repository-root/'.$name, $content);
        }
        $zip->close();
        $bytes = file_get_contents($path);
        unlink($path);

        return $bytes;
    }

    public function test_private_download_pins_commit_excludes_private_files_and_never_forwards_token_to_redirect(): void
    {
        $admin = User::factory()->globalAdmin()->create();
        app(PortalSettings::class)->save($admin, ['bridge_github_token' => 'fictitious-read-only-secret']);
        $commit = str_repeat('a', 40);
        Http::preventStrayRequests();
        Http::fake([
            'https://api.github.com/repos/Owner/Bridge/commits/main' => Http::response($commit),
            'https://api.github.com/repos/Owner/Bridge/zipball/'.$commit => Http::response('', 302, ['Location' => 'https://codeload.github.com/Owner/Bridge/legacy.zip/'.$commit.'?token=temporary']),
            'https://codeload.github.com/*' => Http::response($this->archive(['.env' => 'secret', 'AGENTS.md' => 'private instructions', 'world/auth.sqlite' => 'database'])),
        ]);
        $source = app(GithubBridgeSource::class);
        $this->assertSame($commit, $source->resolve('Owner/Bridge', 'main'));
        $bundle = $source->bundle('Owner/Bridge', $commit);
        $path = tempnam(sys_get_temp_dir(), 'bridge-inspect');
        try {
            file_put_contents($path, $bundle['bytes']);
            $zip = new ZipArchive;
            $zip->open($path);
            $this->assertFalse($zip->locateName('.env'));
            $this->assertFalse($zip->locateName('AGENTS.md'));
            $this->assertFalse($zip->locateName('world/auth.sqlite'));
            $manifest = json_decode($zip->getFromName('bundle.json'), true);
            $this->assertSame($commit, $manifest['git_head']);
            $this->assertSame($bundle['source_sha256'], $manifest['sha256']);
            $this->assertSame(hash('sha256', 'reviewed source'), $manifest['files']['init.lua']);
            $zip->close();
        } finally {
            unlink($path);
        }
        Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://api.github.com/') && $request->hasHeader('Authorization', 'Bearer fictitious-read-only-secret'));
        Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://codeload.github.com/') && ! $request->hasHeader('Authorization'));
    }

    public function test_untrusted_redirect_and_provider_response_are_sanitized(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://api.github.com/*' => Http::response('private-provider-details', 302, ['Location' => 'https://attacker.example/archive'])]);
        try {
            app(GithubBridgeSource::class)->bundle('Owner/Bridge', str_repeat('a', 40));
            $this->fail('Untrusted redirect accepted.');
        } catch (ValidationException $exception) {
            $this->assertStringNotContainsString('private-provider-details', json_encode($exception->errors()));
        }
        Http::assertSentCount(1);
    }

    public function test_traversal_member_is_refused_without_extracting_it(): void
    {
        Http::fake(['https://api.github.com/*' => Http::response($this->archive(['../outside.py' => 'malicious']))]);
        $this->expectException(ValidationException::class);
        app(GithubBridgeSource::class)->bundle('Owner/Bridge', str_repeat('a', 40));
    }

    public function test_old_github_source_is_rejected_before_provisioning(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'bridge-old');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString('root/init.lua', 'old source');
        $zip->close();
        Http::fake(['https://api.github.com/*' => Http::response(file_get_contents($path))]);
        unlink($path);
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('vollständigen Docker-Installer');
        app(GithubBridgeSource::class)->bundle('Owner/Bridge', str_repeat('a', 40));
    }

    public function test_supplied_game_does_not_include_repository_metadata_or_world_credentials(): void
    {
        $root = storage_path('framework/testing/game-'.bin2hex(random_bytes(8)));
        File::ensureDirectoryExists($root.'/.git');
        File::put($root.'/game.conf', 'name = Minetest Game');
        File::put($root.'/.git/config', 'private');
        try {
            $this->assertNotEmpty(app(GithubBridgeSource::class)->game($root));
            File::put($root.'/auth.sqlite', 'private');
            $this->expectException(ValidationException::class);
            app(GithubBridgeSource::class)->game($root);
        } finally {
            File::deleteDirectory($root);
        }
    }

    public function test_github_api_failure_is_sanitized(): void
    {
        Http::fake(['https://api.github.com/*' => Http::response('private-details', 404)]);
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('GitHub-Download nicht bestätigt');
        app(GithubBridgeSource::class)->resolve('Owner/Bridge', 'main');
    }
}
