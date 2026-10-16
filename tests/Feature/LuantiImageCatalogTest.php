<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\LuantiImageCatalog;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LuantiImageCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_loads_real_registry_tags_sorts_versions_and_caches_image_references(): void
    {
        $admin = User::factory()->globalAdmin()->create();
        $this->freezeTime();
        Http::preventStrayRequests();
        Http::fake([
            'https://ghcr.io/token?*' => Http::response(['token' => 'registry-token']),
            'https://ghcr.io/v2/luanti-org/luanti/tags/list?*' => Http::response([
                'name' => 'luanti-org/luanti', 'tags' => ['latest', '5.16.1', 'master', '5.17.0-rc1', '5.17.0', '5.16.1'],
            ]),
        ]);

        $images = app(LuantiImageCatalog::class)->refresh($admin);

        $this->assertSame([
            'ghcr.io/luanti-org/luanti:5.17.0', 'ghcr.io/luanti-org/luanti:5.17.0-rc1',
            'ghcr.io/luanti-org/luanti:5.16.1', 'ghcr.io/luanti-org/luanti:latest', 'ghcr.io/luanti-org/luanti:master',
        ], array_keys($images));
        $this->assertSame('latest (aktuelle stabile Version)', $images['ghcr.io/luanti-org/luanti:latest']);
        $this->assertSame($images, app(LuantiImageCatalog::class)->images());
        $this->assertFalse(app(LuantiImageCatalog::class)->needsRefresh());
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://ghcr.io/token?')
            && $request['scope'] === 'repository:luanti-org/luanti:pull' && ! $request->hasHeader('Authorization'));
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/tags/list?') && $request->hasHeader('Authorization', 'Bearer registry-token'));
    }

    public function test_catalog_paginates_without_following_external_link_or_forwarding_registry_token(): void
    {
        $admin = User::factory()->globalAdmin()->create();
        Http::preventStrayRequests();
        Http::fake([
            'https://ghcr.io/token?*' => Http::response(['token' => 'registry-token']),
            'https://ghcr.io/v2/luanti-org/luanti/tags/list?*' => function (Request $request) {
                return isset($request['last'])
                    ? Http::response(['name' => 'luanti-org/luanti', 'tags' => ['5.17.0']])
                    : Http::response(['name' => 'luanti-org/luanti', 'tags' => ['5.16.1']], 200,
                        ['Link' => '<https://unexpected.example/tags?last=5.16.1>; rel="next"']);
            },
        ]);

        $images = app(LuantiImageCatalog::class)->refresh($admin);

        $this->assertSame(['ghcr.io/luanti-org/luanti:5.17.0', 'ghcr.io/luanti-org/luanti:5.16.1'], array_keys($images));
        Http::assertSentCount(3);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/tags/list?') && ($request['last'] ?? null) === '5.16.1');
        Http::assertNotSent(fn (Request $request): bool => ! str_starts_with($request->url(), 'https://ghcr.io/'));
    }

    /** @return array<string, array{int, mixed}> */
    public static function invalidResponses(): array
    {
        return [
            'provider failure' => [500, ['private-details']], 'rate limited' => [429, ['private-details']],
            'redirect' => [302, []], 'wrong repository' => [200, ['name' => 'other/image', 'tags' => ['5.17.0']]],
            'missing tags' => [200, ['name' => 'luanti-org/luanti']],
            'invalid tag' => [200, ['name' => 'luanti-org/luanti', 'tags' => ['<script>private-details</script>']]],
            'empty list' => [200, ['name' => 'luanti-org/luanti', 'tags' => []]],
            'non-list tags' => [200, ['name' => 'luanti-org/luanti', 'tags' => ['name' => 'latest']]],
        ];
    }

    #[DataProvider('invalidResponses')]
    public function test_bad_registry_response_preserves_previous_catalog(int $status, mixed $body): void
    {
        $admin = User::factory()->globalAdmin()->create();
        $cached = ['images' => ['ghcr.io/luanti-org/luanti:5.16.1' => '5.16.1'], 'fetched_at' => 1];
        Cache::forever(LuantiImageCatalog::CacheKey, $cached);
        Http::preventStrayRequests();
        Http::fake([
            'https://ghcr.io/token?*' => Http::response(['token' => 'registry-token']),
            'https://ghcr.io/v2/luanti-org/luanti/tags/list?*' => Http::response($body, $status),
        ]);

        try {
            app(LuantiImageCatalog::class)->refresh($admin);
            $this->fail('Invalid image catalog was accepted.');
        } catch (ValidationException $exception) {
            $this->assertStringNotContainsString('private-details', $exception->errors()['luanti_docker_image'][0]);
        }

        $this->assertSame($cached, Cache::get(LuantiImageCatalog::CacheKey));
        Http::assertSentCount(2);
    }

    public function test_connection_failure_preserves_previous_catalog(): void
    {
        $admin = User::factory()->globalAdmin()->create();
        $cached = ['images' => ['ghcr.io/luanti-org/luanti:5.16.1' => '5.16.1'], 'fetched_at' => 1];
        Cache::forever(LuantiImageCatalog::CacheKey, $cached);
        Http::preventStrayRequests();
        Http::fake(['https://ghcr.io/token?*' => Http::failedConnection('private-details')]);

        try {
            app(LuantiImageCatalog::class)->refresh($admin);
            $this->fail('Connection failure was ignored.');
        } catch (ValidationException $exception) {
            $this->assertStringNotContainsString('private-details', $exception->errors()['luanti_docker_image'][0]);
        }

        $this->assertSame($cached, Cache::get(LuantiImageCatalog::CacheKey));
        Http::assertSentCount(1);
    }

    public function test_invalid_registry_token_is_rejected_before_requesting_tags(): void
    {
        $admin = User::factory()->globalAdmin()->create();
        Http::preventStrayRequests();
        Http::fake(['https://ghcr.io/token?*' => Http::response(['token' => "secret\r\nInjected: header"])]);

        try {
            app(LuantiImageCatalog::class)->refresh($admin);
            $this->fail('Invalid registry token was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('luanti_docker_image', $exception->errors());
        }

        $this->assertSame([], app(LuantiImageCatalog::class)->images());
        Http::assertSentCount(1);
    }

    public function test_non_admin_cannot_refresh_catalog(): void
    {
        $actor = User::factory()->caseworker()->create();
        Http::preventStrayRequests();

        try {
            app(LuantiImageCatalog::class)->refresh($actor);
            $this->fail('Unauthorized catalog refresh was accepted.');
        } catch (AuthorizationException) {
            Http::assertNothingSent();
        }
    }
}
