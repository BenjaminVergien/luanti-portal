<?php

namespace Tests\Feature;

use App\Models\LuantiServer;
use App\Models\User;
use App\Services\LuantiApiConnectionChecker;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class LuantiApiConnectionCheckerTest extends TestCase
{
    use RefreshDatabase;

    public function test_accepts_authenticated_unknown_job_response_using_one_secure_get(): void
    {
        $admin = User::factory()->globalAdmin()->create();
        Http::preventStrayRequests();
        Http::fake(['https://bridge.example.test/prefix/v1/lifecycle/jobs/connection-check-*' => function (Request $request, array $options): PromiseInterface {
            $this->assertSame(3, $options['connect_timeout']);
            $this->assertSame(8, $options['timeout']);
            $this->assertFalse($options['allow_redirects']);
            $this->assertTrue($options['verify']);

            return Http::response(['version' => 1, 'error' => ['code' => 'unknown_job']], 404);
        }]);

        $result = app(LuantiApiConnectionChecker::class)->check($admin, [
            'api_base_url' => ' https://bridge.example.test/prefix/ ', 'api_token' => 'test-token',
        ]);

        $this->assertSame([
            'successful' => true, 'title' => 'API-Verbindung erfolgreich',
            'body' => 'Die Bridge-API ist erreichbar und akzeptiert den API-Token.',
        ], $result);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && $request->hasHeader('Authorization', 'Bearer test-token')
            && $request->hasHeader('Accept', 'application/json')
            && $request->body() === ''
            && Str::isUuid(Str::afterLast($request->url(), 'connection-check-')));
        $this->assertDatabaseCount('luanti_servers', 0);
    }

    /** @return array<string, array{int, array|string, string}> */
    public static function unsuccessfulResponses(): array
    {
        return [
            '401 unauthorized' => [401, ['version' => 1, 'error' => ['code' => 'unauthorized']], 'API-Token abgelehnt'],
            '500 server error' => [500, 'private upstream error', 'API-Fehler'],
            '503 unavailable' => [503, ['version' => 1, 'error' => ['code' => 'state_unavailable']], 'API-Fehler'],
            'redirect' => [302, '', 'Unerwartete API-Antwort'],
            'HTML 404' => [404, '<html>Not found: private upstream error</html>', 'Unerwartete API-Antwort'],
            'unrelated JSON 404' => [404, ['detail' => 'Not Found'], 'Unerwartete API-Antwort'],
            'wrong version' => [404, ['version' => 2, 'error' => ['code' => 'unknown_job']], 'Unerwartete API-Antwort'],
            'string version' => [404, ['version' => '1', 'error' => ['code' => 'unknown_job']], 'Unerwartete API-Antwort'],
            'wrong code' => [404, ['version' => 1, 'error' => ['code' => 'unknown_world']], 'Unerwartete API-Antwort'],
            'wrong status' => [200, ['version' => 1, 'error' => ['code' => 'unknown_job']], 'Unerwartete API-Antwort'],
        ];
    }

    #[DataProvider('unsuccessfulResponses')]
    public function test_reports_response_failures_without_exposing_remote_content(int $status, array|string $body, string $title): void
    {
        $admin = User::factory()->globalAdmin()->create();
        Http::preventStrayRequests();
        Http::fake(['https://bridge.example.test/v1/lifecycle/jobs/connection-check-*' => Http::response($body, $status, ['Location' => 'https://another.example.test'])]);

        $result = app(LuantiApiConnectionChecker::class)->check($admin, [
            'api_base_url' => 'https://bridge.example.test', 'api_token' => 'private-test-token',
        ]);

        $this->assertFalse($result['successful']);
        $this->assertSame($title, $result['title']);
        $this->assertStringNotContainsString('private', json_encode($result, JSON_THROW_ON_ERROR));
        Http::assertSentCount(1);
    }

    /** @return array<string, array{string}> */
    public static function connectionFailures(): array
    {
        return [
            'timeout' => ['cURL error 28: timeout private-test-token'],
            'TLS failure' => ['cURL error 60: certificate private-test-token'],
            'DNS failure' => ['cURL error 6: DNS private-test-token'],
        ];
    }

    #[DataProvider('connectionFailures')]
    public function test_reports_connection_failures_without_retrying_or_exposing_exception_text(string $message): void
    {
        $admin = User::factory()->globalAdmin()->create();
        $attempts = 0;
        Http::preventStrayRequests();
        Http::fake(['https://bridge.example.test/v1/lifecycle/jobs/connection-check-*' => function (Request $request) use (&$attempts, $message): PromiseInterface {
            $attempts++;

            return Http::failedConnection($message)($request);
        }]);

        $result = app(LuantiApiConnectionChecker::class)->check($admin, [
            'api_base_url' => 'https://bridge.example.test', 'api_token' => 'private-test-token',
        ]);

        $this->assertFalse($result['successful']);
        $this->assertSame('API nicht erreichbar', $result['title']);
        $this->assertStringNotContainsString('private-test-token', json_encode($result, JSON_THROW_ON_ERROR));
        $this->assertSame(1, $attempts);
    }

    /** @return array<string, array{mixed, mixed, string}> */
    public static function invalidSettings(): array
    {
        return [
            'missing URL' => [null, 'test-token', 'API-Konfiguration fehlt'],
            'missing token' => ['https://bridge.example.test', null, 'API-Konfiguration fehlt'],
            'blank URL' => ['  ', 'test-token', 'API-Konfiguration fehlt'],
            'blank token' => ['https://bridge.example.test', '  ', 'API-Konfiguration fehlt'],
            'HTTP URL' => ['http://bridge.example.test', 'test-token', 'API-Konfiguration ungültig'],
            'malformed URL' => ['not-a-url', 'test-token', 'API-Konfiguration ungültig'],
            'URL credentials' => ['https://user:password@bridge.example.test', 'test-token', 'API-Konfiguration ungültig'],
            'URL query' => ['https://bridge.example.test?key=value', 'test-token', 'API-Konfiguration ungültig'],
            'empty URL query' => ['https://bridge.example.test?', 'test-token', 'API-Konfiguration ungültig'],
            'URL fragment' => ['https://bridge.example.test#part', 'test-token', 'API-Konfiguration ungültig'],
            'array URL' => [['https://bridge.example.test'], 'test-token', 'API-Konfiguration ungültig'],
            'array token' => ['https://bridge.example.test', ['test-token'], 'API-Konfiguration ungültig'],
            'token header injection' => ['https://bridge.example.test', "token\r\nX-Header: value", 'API-Konfiguration ungültig'],
        ];
    }

    #[DataProvider('invalidSettings')]
    public function test_refuses_missing_or_invalid_connection_settings_without_sending_requests(mixed $url, mixed $token, string $title): void
    {
        $admin = User::factory()->globalAdmin()->create();
        Http::preventStrayRequests();

        $result = app(LuantiApiConnectionChecker::class)->check($admin, ['api_base_url' => $url, 'api_token' => $token]);

        $this->assertFalse($result['successful']);
        $this->assertSame($title, $result['title']);
        Http::assertNothingSent();
    }

    public function test_saved_check_reads_current_credentials_without_changing_the_record(): void
    {
        $admin = User::factory()->globalAdmin()->create();
        $server = LuantiServer::factory()->create(['api_base_url' => 'https://old.example.test', 'api_token' => 'old-token']);
        LuantiServer::query()->findOrFail($server->id)->update(['api_base_url' => 'https://new.example.test', 'api_token' => 'new-token']);
        $attributes = $server->fresh()->getRawOriginal();
        Http::preventStrayRequests();
        Http::fake(['https://new.example.test/v1/lifecycle/jobs/connection-check-*' => Http::response(['version' => 1, 'error' => ['code' => 'unknown_job']], 404)]);

        $result = app(LuantiApiConnectionChecker::class)->check($admin, server: $server);

        $this->assertTrue($result['successful']);
        Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer new-token'));
        Http::assertSentCount(1);
        $this->assertSame($attributes, $server->fresh()->getRawOriginal());
    }

    /** @return array<string, array{string}> */
    public static function deniedRoles(): array
    {
        return ['caseworker' => ['caseworker'], 'ordinary' => ['ordinary'], 'inactive' => ['inactive'], 'unverified' => ['unverified']];
    }

    #[DataProvider('deniedRoles')]
    public function test_refuses_unauthorized_users_before_reading_tokens_or_sending_requests(string $role): void
    {
        $actor = match ($role) {
            'caseworker' => User::factory()->caseworker()->create(),
            'inactive' => User::factory()->globalAdmin()->inactive()->create(),
            'unverified' => User::factory()->globalAdmin()->unverified()->create(),
            default => User::factory()->create(),
        };
        $server = LuantiServer::factory()->create(['api_base_url' => 'https://bridge.example.test']);
        $server->getConnection()->table('luanti_servers')->where('id', $server->id)->update(['api_token' => 'not-decryptable']);
        Http::preventStrayRequests();

        try {
            app(LuantiApiConnectionChecker::class)->check($actor, server: $server);
            $this->fail('Unauthorized connection check was accepted.');
        } catch (AuthorizationException|HttpException $exception) {
            $this->assertSame(403, $exception instanceof HttpException ? $exception->getStatusCode() : ($exception->status() ?? 403));
        }

        Http::assertNothingSent();
    }
}
