<?php

namespace Tests\Feature;

use App\Enums\InternalRole;
use App\Models\User;
use App\Services\PortalAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class AccountManagementTest extends TestCase
{
    use RefreshDatabase;

    private PortalAccess $access;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->access = app(PortalAccess::class);
        $this->admin = User::factory()->globalAdmin()->create();
    }

    /** @return array{name: string, email: string, password: string, password_confirmation: string} */
    private function accountData(): array
    {
        return ['name' => 'Neue Person', 'email' => 'new@example.test', 'password' => 'Safe-Account-Password!', 'password_confirmation' => 'Safe-Account-Password!'];
    }

    public function test_created_account_defaults_to_unverified_without_privileges_or_mail(): void
    {
        Notification::fake();
        $user = $this->access->createAccount($this->admin, [...$this->accountData(), 'name' => ' Neue Person ', 'email' => ' NEW@example.test ', 'internal_role' => 'global_admin', 'email_verified_at' => now(), 'remember_token' => 'injected']);

        $this->assertSame('Neue Person', $user->name);
        $this->assertSame('new@example.test', $user->email);
        $this->assertTrue($user->is_active);
        $this->assertFalse($user->hasVerifiedEmail());
        $this->assertNull($user->internal_role);
        $this->assertNull($user->remember_token);
        $this->assertSame(0, $user->memberships()->count());
        $this->assertTrue(Hash::check('Safe-Account-Password!', $user->password));
        Notification::assertNothingSent();
    }

    public function test_creation_accepts_explicit_fallback_verification_and_inactive_status(): void
    {
        $user = $this->access->createAccount($this->admin, [...$this->accountData(), 'email_verified' => true, 'is_active' => false]);

        $this->assertTrue($user->hasVerifiedEmail());
        $this->assertFalse($user->is_active);
        $this->assertNull($user->internal_role);
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function invalidAccountData(): array
    {
        return [
            'empty name' => [['name' => '  '], 'name'],
            'long name' => [['name' => str_repeat('n', 256)], 'name'],
            'invalid email' => [['email' => 'invalid'], 'email'],
            'short password' => [['password' => 'short', 'password_confirmation' => 'short'], 'password'],
            'mismatched confirmation' => [['password_confirmation' => 'different'], 'password'],
            'invalid verification flag' => [['email_verified' => 'yes'], 'email_verified'],
        ];
    }

    #[DataProvider('invalidAccountData')]
    public function test_invalid_creation_does_not_create_an_account(array $changes, string $field): void
    {
        try {
            $this->access->createAccount($this->admin, [...$this->accountData(), ...$changes]);
            $this->fail('Invalid account accepted');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
        }
        $this->assertDatabaseCount('users', 1);
    }

    public function test_duplicate_normalized_email_is_rejected_on_create_and_update(): void
    {
        $existing = User::factory()->create(['email' => 'existing@example.test']);
        $target = User::factory()->create();
        $original = $target->fresh()->getAttributes();

        foreach (['create', 'update'] as $operation) {
            try {
                $data = [...$this->accountData(), 'email' => ' EXISTING@example.test '];
                if ($operation === 'create') {
                    $this->access->createAccount($this->admin, $data);
                } else {
                    $this->access->updateAccount($this->admin, $target, null, false, $data);
                }
                $this->fail('Duplicate email accepted');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('email', $exception->errors());
            }
        }

        $this->assertSame($original, $target->fresh()->getAttributes());
        $this->assertSame('existing@example.test', $existing->fresh()->email);
        $this->assertDatabaseCount('users', 3);
    }

    public function test_edits_preserve_blank_password_and_replace_explicit_password(): void
    {
        $target = User::factory()->create();
        $hash = $target->password;
        $verifiedAt = $target->email_verified_at->toDateTimeString();
        $this->access->updateAccount($this->admin, $target, null, false, ['name' => ' Renamed ', 'email' => strtoupper($target->email), 'password' => '']);

        $this->assertSame('Renamed', $target->fresh()->name);
        $this->assertSame($hash, $target->fresh()->password);
        $this->assertSame($verifiedAt, $target->fresh()->email_verified_at->toDateTimeString());
        $this->assertFalse($target->fresh()->is_active);

        $this->access->updateAccount($this->admin, $target, null, true, ['password' => 'Replacement-Password!', 'password_confirmation' => 'Replacement-Password!']);
        $this->assertTrue(Hash::check('Replacement-Password!', $target->fresh()->password));
    }

    public function test_verification_can_be_granted_preserved_and_revoked(): void
    {
        $this->freezeTime();
        $target = User::factory()->unverified()->create();
        $this->access->updateAccount($this->admin, $target, null, true, ['email_verified' => true]);
        $verifiedAt = $target->fresh()->email_verified_at->toDateTimeString();
        $this->assertSame(now()->toDateTimeString(), $verifiedAt);
        $this->travel(1)->hour();
        $this->access->updateAccount($this->admin, $target, null, true, ['email_verified' => true]);
        $this->assertSame($verifiedAt, $target->fresh()->email_verified_at->toDateTimeString());
        $this->access->updateAccount($this->admin, $target, null, true, ['email_verified' => false]);
        $this->assertFalse($target->fresh()->hasVerifiedEmail());
    }

    public function test_email_changes_clear_verification_unless_explicitly_confirmed(): void
    {
        $this->freezeTime();
        $target = User::factory()->create();
        $this->access->updateAccount($this->admin, $target, null, true, ['email' => 'changed@example.test']);
        $this->assertFalse($target->fresh()->hasVerifiedEmail());
        $this->travel(1)->hour();
        $this->access->updateAccount($this->admin, $target, null, true, ['email' => 'confirmed@example.test', 'email_verified' => true]);
        $this->assertSame(now()->toDateTimeString(), $target->fresh()->email_verified_at->toDateTimeString());
    }

    public function test_internal_verification_constraints_use_the_final_account_state(): void
    {
        $target = User::factory()->caseworker()->unverified()->create();
        $this->access->updateAccount($this->admin, $target, InternalRole::Caseworker, true, ['email_verified' => true]);
        $this->assertTrue($target->fresh()->isInternal());

        try {
            $this->access->updateAccount($this->admin, $target, InternalRole::Caseworker, true, ['name' => 'Rejected', 'email_verified' => false]);
            $this->fail('Unverified internal role accepted');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('internal_role', $exception->errors());
        }
        $this->assertNotSame('Rejected', $target->fresh()->name);
        $this->assertTrue($target->fresh()->hasVerifiedEmail());

        $this->access->updateAccount($this->admin, $target, null, true, ['email_verified' => false]);
        $this->assertNull($target->fresh()->internal_role);
        $this->assertFalse($target->fresh()->hasVerifiedEmail());
    }

    public function test_last_admin_protection_rolls_back_every_account_field(): void
    {
        $original = $this->admin->fresh()->getAttributes();
        try {
            $this->access->updateAccount($this->admin, $this->admin, null, true, [...$this->accountData(), 'email_verified' => false]);
            $this->fail('Last admin access removed');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('internal_role', $exception->errors());
        }
        $this->assertSame($original, $this->admin->fresh()->getAttributes());
    }

    public function test_verification_does_not_allow_direct_internal_role_promotions(): void
    {
        $target = User::factory()->unverified()->create();
        $original = $target->fresh()->getAttributes();
        try {
            $this->access->updateAccount($this->admin, $target, InternalRole::GlobalAdmin, true, ['name' => 'Rejected', 'email_verified' => true]);
            $this->fail('Direct promotion accepted');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('internal_role', $exception->errors());
        }
        $this->assertSame($original, $target->fresh()->getAttributes());
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function unauthorizedActors(): array
    {
        return [
            'caseworker' => [['internal_role' => InternalRole::Caseworker]],
            'ordinary user' => [['internal_role' => null]],
            'inactive admin' => [['is_active' => false]],
            'unverified admin' => [['email_verified_at' => null]],
        ];
    }

    #[DataProvider('unauthorizedActors')]
    public function test_services_and_policy_reject_revoked_or_insufficient_permissions(array $attributes): void
    {
        $target = User::factory()->create();
        $this->admin->fresh()->forceFill($attributes)->save();
        $this->assertFalse(Gate::forUser($this->admin)->allows('create', User::class));
        $this->assertFalse(Gate::forUser($this->admin)->allows('update', $target));

        foreach (['create', 'update'] as $operation) {
            try {
                if ($operation === 'create') {
                    $this->access->createAccount($this->admin, $this->accountData());
                } else {
                    $this->access->updateAccount($this->admin, $target, null, false, ['name' => 'Rejected']);
                }
                $this->fail('Unauthorized write accepted');
            } catch (AuthorizationException|HttpException) {
                $this->assertNotSame('Rejected', $target->fresh()->name);
            }
        }

        $this->assertDatabaseCount('users', 2);
        $this->assertTrue($target->fresh()->is_active);
    }
}
