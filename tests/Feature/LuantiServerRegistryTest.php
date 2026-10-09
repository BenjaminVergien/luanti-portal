<?php

namespace Tests\Feature;

use App\Enums\InternalRole;
use App\Models\LuantiServer;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Services\LuantiServerRegistry;
use Database\Seeders\LuantiServerSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class LuantiServerRegistryTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{name: string, domain: string, game_port: int} */
    private function serverData(): array
    {
        return ['name' => 'Testserver', 'domain' => 'alpha.example.test', 'game_port' => 30000];
    }

    public function test_admin_registers_multiple_servers_without_contacting_them(): void
    {
        $admin = User::factory()->globalAdmin()->create();
        $registry = app(LuantiServerRegistry::class);
        Http::preventStrayRequests();

        $first = $registry->create($admin, $this->serverData());
        $second = $registry->create($admin, [
            'name' => ' Zweiter Server ', 'notes' => "Erste Zeile\nZweite Zeile", 'domain' => 'second.example.test',
            'game_port' => 65535, 'api_base_url' => 'https://bridge.example.test:8443/api/v1',
        ]);

        $this->assertDatabaseCount('luanti_servers', 2);
        $this->assertDatabaseHas('luanti_servers', ['id' => $first->id, ...$this->serverData(), 'api_base_url' => null, 'api_token' => null]);
        $this->assertDatabaseHas('luanti_servers', [
            'id' => $second->id, 'name' => 'Zweiter Server', 'notes' => "Erste Zeile\nZweite Zeile",
            'domain' => 'second.example.test', 'game_port' => 65535, 'api_base_url' => 'https://bridge.example.test:8443/api/v1', 'api_token' => null,
        ]);
        $this->assertSame([$first->id, $second->id], $registry->query($admin)->orderBy('id')->pluck('id')->all());
        Http::assertNothingSent();
    }

    public static function validDomainsAndPorts(): array
    {
        return [
            'domain and minimum port' => ['luanti.example.test', 1],
            'domain and maximum port' => ['luanti.example.test', 65535],
            'subdomain' => ['world-1.luanti.example.test', 30000],
            'punycode' => ['xn--bcher-kva.example.test', 30000],
        ];
    }

    #[DataProvider('validDomainsAndPorts')]
    public function test_registers_valid_domaines_and_port_boundaries(string $domain, int $gamePort): void
    {
        $admin = User::factory()->globalAdmin()->create();

        app(LuantiServerRegistry::class)->create($admin, [...$this->serverData(), 'domain' => $domain, 'game_port' => $gamePort]);

        $this->assertDatabaseHas('luanti_servers', ['domain' => $domain, 'game_port' => $gamePort]);
    }

    public static function invalidServerData(): array
    {
        return [
            'empty name' => ['name', '', 'required'],
            'whitespace name' => ['name', '   ', 'required'],
            'long name' => ['name', str_repeat('n', 256), 'max'],
            'non-string name' => ['name', ['invalid'], 'string'],
            'non-string notes' => ['notes', [], 'string'],
            'missing domain' => ['domain', null, 'required'],
            'IPv4' => ['domain', '192.0.2.10', 'regex'],
            'IPv6' => ['domain', '2001:db8::10', 'regex'],
            'URL instead of domain' => ['domain', 'https://server.example.test', 'regex'],
            'domain with port' => ['domain', 'server.example.test:30000', 'regex'],
            'domain with path' => ['domain', 'server.example.test/api', 'regex'],
            'domain with space' => ['domain', 'server example.test', 'regex'],
            'empty label' => ['domain', 'server..example.test', 'regex'],
            'leading hyphen' => ['domain', '-server.example.test', 'regex'],
            'trailing hyphen' => ['domain', 'server-.example.test', 'regex'],
            'long label' => ['domain', str_repeat('a', 64).'.example.test', 'regex'],
            'long domain' => ['domain', str_repeat('a', 254), 'max'],
            'missing port' => ['game_port', null, 'required'],
            'zero port' => ['game_port', 0, 'between'],
            'negative port' => ['game_port', -1, 'between'],
            'high port' => ['game_port', 65536, 'between'],
            'fractional port' => ['game_port', 30000.5, 'integer'],
            'non-numeric port' => ['game_port', 'port', 'integer'],
            'HTTP endpoint' => ['api_base_url', 'http://bridge.example.test/api', 'url'],
            'invalid endpoint' => ['api_base_url', 'bridge.example.test', 'url'],
            'long endpoint' => ['api_base_url', 'https://bridge.example.test/'.str_repeat('a', 2048), 'max'],
            'non-string token' => ['api_token', [], 'string'],
        ];
    }

    public function test_domain_is_trimmed_and_lowercased_without_resolving_dns(): void
    {
        $admin = User::factory()->globalAdmin()->create();

        app(LuantiServerRegistry::class)->create($admin, [...$this->serverData(), 'domain' => ' LuAnTi.Example.TEST ']);

        $this->assertDatabaseHas('luanti_servers', ['domain' => 'luanti.example.test']);
    }

    public function test_domain_migration_preserves_existing_addresses_and_encrypted_credentials(): void
    {
        $server = LuantiServer::factory()->create(['api_token' => 'existing-token', 'notes' => 'Existing notes']);
        $ciphertext = $server->getRawOriginal('api_token');
        $migration = require database_path('migrations/2026_10_09_195727_replace_luanti_server_ip_address_with_domain.php');
        $migration->down();
        DB::table('luanti_servers')->where('id', $server->id)->update(['ip_address' => '192.0.2.10']);

        $migration->up();

        $this->assertDatabaseHas('luanti_servers', [
            'id' => $server->id, 'domain' => '192.0.2.10', 'notes' => 'Existing notes', 'api_token' => $ciphertext,
        ]);
        $this->assertSame('existing-token', $server->fresh()->api_token);
    }

    #[DataProvider('invalidServerData')]
    public function test_invalid_configuration_is_rejected_without_saving(string $field, mixed $value, string $rule): void
    {
        $admin = User::factory()->globalAdmin()->create();
        $data = [...$this->serverData(), $field => $value];

        try {
            app(LuantiServerRegistry::class)->create($admin, $data);
            $this->fail('Invalid server configuration was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
            $this->assertArrayHasKey(ucfirst($rule), $exception->validator->failed()[$field]);
            $this->assertNotEmpty($exception->errors()[$field][0]);
        }

        $this->assertDatabaseCount('luanti_servers', 0);
    }

    public function test_token_is_encrypted_and_excluded_from_serialization(): void
    {
        $admin = User::factory()->globalAdmin()->create();
        $token = 'bridge-issued-secret-token';

        $server = app(LuantiServerRegistry::class)->create($admin, [...$this->serverData(), 'api_token' => $token]);

        $ciphertext = DB::table('luanti_servers')->where('id', $server->id)->value('api_token');
        $this->assertNotSame($token, $ciphertext);
        $this->assertStringNotContainsString($token, $ciphertext);
        $this->assertSame($token, $server->fresh()->api_token);
        $this->assertSame([
            'name', 'notes', 'domain', 'game_port', 'api_base_url', 'updated_at', 'created_at', 'id',
        ], array_keys($server->toArray()));
        $this->assertStringNotContainsString($token, $server->toJson());
        $this->assertStringNotContainsString($ciphertext, $server->toJson());
    }

    public static function blankTokens(): array
    {
        return ['omitted' => [[]], 'empty' => [['api_token' => '']], 'null' => [['api_token' => null]], 'whitespace' => [['api_token' => '  ']]];
    }

    /** @param array{api_token?: ?string} $tokenData */
    #[DataProvider('blankTokens')]
    public function test_blank_edit_preserves_existing_token(array $tokenData): void
    {
        $admin = User::factory()->globalAdmin()->create();
        $server = LuantiServer::factory()->create(['api_token' => 'original-token']);
        $ciphertext = $server->getRawOriginal('api_token');

        app(LuantiServerRegistry::class)->update($admin, $server, [...$this->serverData(), ...$tokenData, 'name' => 'Geändert']);

        $this->assertDatabaseHas('luanti_servers', ['id' => $server->id, 'name' => 'Geändert', 'api_token' => $ciphertext]);
        $this->assertSame('original-token', $server->fresh()->api_token);
    }

    public function test_admin_replaces_and_explicitly_removes_token(): void
    {
        $admin = User::factory()->globalAdmin()->create();
        $server = LuantiServer::factory()->create(['api_token' => 'old-token', 'api_base_url' => 'https://bridge.example.test']);
        $registry = app(LuantiServerRegistry::class);

        $registry->update($admin, $server, [...$this->serverData(), 'api_token' => 'new-token']);

        $this->assertSame('new-token', $server->fresh()->api_token);
        $this->assertDatabaseHas('luanti_servers', ['id' => $server->id, 'api_base_url' => null]);

        $registry->removeToken($admin, $server);

        $this->assertDatabaseHas('luanti_servers', ['id' => $server->id, 'api_token' => null]);
    }

    public static function unauthorizedActors(): array
    {
        return [
            'caseworker' => ['caseworker'], 'organization manager' => ['manager'], 'ordinary user' => ['ordinary'],
            'inactive admin' => ['inactive'], 'unverified admin' => ['unverified'],
        ];
    }

    #[DataProvider('unauthorizedActors')]
    public function test_non_admins_cannot_read_or_change_server_records(string $role): void
    {
        $actor = match ($role) {
            'caseworker' => User::factory()->caseworker()->create(),
            'inactive' => User::factory()->globalAdmin()->inactive()->create(),
            'unverified' => User::factory()->globalAdmin()->unverified()->create(),
            default => User::factory()->create(),
        };
        if ($role === 'manager') {
            OrganizationMembership::factory()->manager()->create(['user_id' => $actor->id]);
        }
        $server = LuantiServer::factory()->create(['api_token' => 'protected-token']);
        $originalAttributes = $server->fresh()->getRawOriginal();
        $registry = app(LuantiServerRegistry::class);
        $gate = Gate::forUser($actor);

        foreach (['viewAny', 'create'] as $ability) {
            $this->assertFalse($gate->allows($ability, LuantiServer::class));
        }
        foreach (['view', 'update', 'delete'] as $ability) {
            $this->assertFalse($gate->allows($ability, $server));
        }
        $operations = [
            fn () => $registry->query($actor),
            fn () => $registry->create($actor, $this->serverData()),
            fn () => $registry->update($actor, $server, $this->serverData()),
            fn () => $registry->removeToken($actor, $server),
            fn () => $registry->delete($actor, $server),
        ];
        foreach ($operations as $operation) {
            try {
                $operation();
                $this->fail('Unauthorized server access was accepted.');
            } catch (AuthorizationException|HttpException $exception) {
                if ($exception instanceof HttpException) {
                    $this->assertSame(403, $exception->getStatusCode());
                }
            }
        }

        $this->assertDatabaseCount('luanti_servers', 1);
        $this->assertSame($originalAttributes, $server->fresh()->getRawOriginal());
    }

    public function test_service_and_policy_use_current_permissions_instead_of_stale_admin(): void
    {
        $admin = User::factory()->globalAdmin()->create();
        $server = LuantiServer::factory()->create();
        User::query()->whereKey($admin->id)->update(['internal_role' => InternalRole::Caseworker]);

        $this->assertFalse(Gate::forUser($admin)->allows('update', $server));
        $this->expectException(AuthorizationException::class);

        app(LuantiServerRegistry::class)->update($admin, $server, $this->serverData());
    }

    public function test_local_example_seeder_is_optional_idempotent_and_credential_free(): void
    {
        $this->app->instance('env', 'local');

        $this->seed(LuantiServerSeeder::class);
        $this->seed(LuantiServerSeeder::class);

        $this->assertDatabaseCount('luanti_servers', 1);
        $this->assertDatabaseHas('luanti_servers', ['domain' => 'luanti.example.test', 'api_base_url' => null, 'api_token' => null]);
    }

    public function test_example_seeder_is_refused_outside_local_environment(): void
    {
        $this->expectException(RuntimeException::class);

        $this->seed(LuantiServerSeeder::class);
    }
}
