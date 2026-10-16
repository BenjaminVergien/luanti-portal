<?php

namespace Tests\Feature;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ApplicationUrlTest extends TestCase
{
    private mixed $applicationUrl = 'https://portal.example.test:8443';

    private string $applicationEnvironment = 'testing';

    protected function tearDown(): void
    {
        Request::setTrustedHosts([]);
        Request::setTrustedProxies([], 0);
        parent::tearDown();
    }

    public function createApplication(): Application
    {
        $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
        $app->afterBootstrapping(LoadConfiguration::class, function (Application $app): void {
            $app['config']->set('app.url', $this->applicationUrl);
            $app->instance('env', $this->applicationEnvironment);
        });
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    /** @return array<string, array{string, string}> */
    public static function validUrls(): array
    {
        return [
            'local development' => ['http://127.0.0.1:8000', 'local'],
            'production HTTPS' => ['https://portal.example.test:8443', 'production'],
            'base path' => ['https://portal.example.test:8443/platform/', 'production'],
            'uppercase scheme' => ['HTTPS://portal.example.test', 'production'],
        ];
    }

    #[DataProvider('validUrls')]
    public function test_boot_pins_generated_urls_even_when_the_request_changes(string $url, string $environment): void
    {
        $this->applicationUrl = $url;
        $this->applicationEnvironment = $environment;
        $this->refreshApplication();
        URL::setRequest(Request::create('http://attacker.example:9123/'));

        $expected = rtrim(preg_replace('/^https:/i', 'https:', $url), '/').'/zugang';
        $this->assertSame($expected, route('access'));
        $this->assertSame($expected, url('/zugang'));
    }

    /** @return array<string, array{mixed}> */
    public static function invalidUrls(): array
    {
        return [
            'missing URL' => [null],
            'empty URL' => [''],
            'array' => [['https://portal.example.test']],
            'relative URL' => ['/portal'],
            'protocol relative URL' => ['//portal.example.test'],
            'missing host' => ['https:///portal'],
            'unsupported scheme' => ['ftp://portal.example.test'],
            'credentials' => ['https://user:password@portal.example.test'],
            'username only' => ['https://user@portal.example.test'],
            'query' => ['https://portal.example.test?key=value'],
            'empty query' => ['https://portal.example.test?'],
            'fragment' => ['https://portal.example.test#fragment'],
            'empty fragment' => ['https://portal.example.test#'],
            'invalid port' => ['https://portal.example.test:99999'],
            'whitespace' => ['https://portal.example.test/ with space'],
            'control characters' => ["https://portal.example.test/\r\nheader"],
        ];
    }

    #[DataProvider('invalidUrls')]
    public function test_invalid_configuration_stops_application_boot(mixed $url): void
    {
        $this->applicationUrl = $url;
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('APP_URL must be an absolute HTTP(S) URL');
        $this->refreshApplication();
    }

    public function test_production_requires_https(): void
    {
        $this->applicationUrl = 'http://portal.example.test';
        $this->applicationEnvironment = 'production';
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('APP_URL must use HTTPS in production.');
        $this->refreshApplication();
    }

    /** @return array<string, array{string}> */
    public static function hostileHosts(): array
    {
        return [
            'unrelated' => ['attacker.example'],
            'subdomain' => ['attacker.portal.example.test'],
            'suffix' => ['portal.example.test.attacker.example'],
            'regex lookalike' => ['portalXexampleXtest'],
        ];
    }

    #[DataProvider('hostileHosts')]
    public function test_production_rejects_unapproved_hosts_before_mail_dispatch(string $host): void
    {
        Mail::fake();
        Queue::fake();
        $this->app->instance('env', 'production');

        foreach (['/admin/password-reset/request', '/portal/password-reset/request', '/portal/register'] as $path) {
            $this->get('https://'.$host.$path)->assertStatus(400);
        }
        $this->postJson('https://'.$host.'/livewire/update', ['components' => []])->assertStatus(400);
        Mail::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_production_accepts_canonical_host_and_ignores_untrusted_proxy_headers(): void
    {
        $this->app->instance('env', 'production');
        $this->withHeaders([
            'X-Forwarded-Host' => 'attacker.example',
            'X-Forwarded-Proto' => 'http',
            'X-Forwarded-Port' => '9123',
            'X-Forwarded-Prefix' => '/attacker',
        ])->get($this->applicationUrl.'/admin/password-reset/request')->assertOk();

        $this->assertSame($this->applicationUrl.'/zugang', route('access'));
    }

    public function test_local_and_testing_environments_keep_the_framework_host_validation_bypass(): void
    {
        foreach (['local', 'testing'] as $environment) {
            $this->app->instance('env', $environment);
            $this->get('http://attacker.example/admin/password-reset/request')->assertOk();
            $this->assertSame($this->applicationUrl.'/zugang', route('access'));
        }
    }

    public function test_trusted_proxy_cannot_supply_an_unapproved_effective_hostname(): void
    {
        Mail::fake();
        Queue::fake();
        $this->app->instance('env', 'production');
        TrustProxies::at(['127.0.0.1']);
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->withHeaders(['X-Forwarded-Host' => 'attacker.example'])
            ->get($this->applicationUrl.'/admin/password-reset/request')->assertStatus(400);
        Mail::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_trusted_proxy_cannot_change_the_canonical_scheme_port_or_base_path(): void
    {
        $this->app->instance('env', 'production');
        TrustProxies::at(['127.0.0.1']);
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->withHeaders([
                'X-Forwarded-Host' => 'portal.example.test',
                'X-Forwarded-Proto' => 'http',
                'X-Forwarded-Port' => '9123',
                'X-Forwarded-Prefix' => '/attacker',
            ])->get($this->applicationUrl.'/admin/password-reset/request')->assertOk();

        $this->assertSame($this->applicationUrl.'/zugang', route('access'));
    }
}
