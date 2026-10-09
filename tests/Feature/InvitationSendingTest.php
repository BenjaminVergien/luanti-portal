<?php

namespace Tests\Feature;

use App\Enums\InternalRole;
use App\Enums\MembershipRole;
use App\Mail\PortalInvitation;
use App\Models\Invitation;
use App\Models\Organization;
use App\Models\User;
use App\Services\PortalAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class InvitationSendingTest extends TestCase
{
    use RefreshDatabase;

    private PortalAccess $access;

    private User $admin;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->access = app(PortalAccess::class);
        $this->admin = User::factory()->globalAdmin()->create();
        $this->organization = Organization::factory()->create();
        $this->freezeTime();
    }

    public static function sendingLimits(): array
    {
        return [
            'actor minute' => ['actor_per_minute', 'actor', 60],
            'actor hour' => ['actor_per_hour', 'actor', 3600],
            'organization hour' => ['organization_per_hour', 'organization', 3600],
            'recipient hour' => ['recipient_per_hour', 'recipient', 3600],
            'recipient day' => ['recipient_per_day', 'recipient', 86400],
            'global minute' => ['global_per_minute', 'global', 60],
            'global hour' => ['global_per_hour', 'global', 3600],
        ];
    }

    #[DataProvider('sendingLimits')]
    public function test_limits_reject_allocation_and_mail_until_the_window_expires(string $limit, string $scope, int $seconds): void
    {
        $this->isolateLimit($limit, 2);
        for ($i = 0; $i < 2; $i++) {
            [$actor, $organization, $email] = $this->invitationContext($scope, $i);
            $this->access->invite($actor, $email, $organization, MembershipRole::Staff);
        }

        [$actor, $organization, $email] = $this->invitationContext($scope, 2);
        $this->assertRejected(fn () => $this->access->invite($actor, $email, $organization, MembershipRole::Staff), 'Versandlimit');
        $this->assertDatabaseCount('invitations', 2);
        Mail::assertSent(PortalInvitation::class, 2);

        $this->travel($seconds + 1)->seconds();
        $this->access->invite($actor, $email, $organization, MembershipRole::Staff);
        $this->assertDatabaseCount('invitations', 3);
        Mail::assertSent(PortalInvitation::class, 3);
    }

    #[DataProvider('sendingLimits')]
    public function test_failed_delivery_still_consumes_each_quota(string $limit, string $scope, int $seconds): void
    {
        $this->isolateLimit($limit, 2);
        Mail::shouldReceive('to')->twice()->andReturnSelf();
        Mail::shouldReceive('send')->twice()->andThrow(new RuntimeException('SMTP unavailable'));
        for ($i = 0; $i < 2; $i++) {
            [$actor, $organization, $email] = $this->invitationContext($scope, $i);
            try {
                $this->access->invite($actor, $email, $organization, MembershipRole::Staff);
                $this->fail('Failed delivery was not reported.');
            } catch (RuntimeException $exception) {
                $this->assertSame('SMTP unavailable', $exception->getMessage());
            }
        }

        [$actor, $organization, $email] = $this->invitationContext($scope, 2);
        $this->assertRejected(fn () => $this->access->invite($actor, $email, $organization, MembershipRole::Staff), 'Versandlimit');
        $this->assertDatabaseCount('invitations', 2);
        $this->assertSame(2, Invitation::query()->whereNotNull('revoked_at')->count());
    }

    public function test_duplicate_organization_invitation_is_blocked_across_senders_and_email_case(): void
    {
        $this->access->invite($this->admin, 'person@example.test', $this->organization, MembershipRole::Staff);

        $this->assertRejected(fn () => $this->access->invite(User::factory()->caseworker()->create(), ' PERSON@EXAMPLE.TEST ', $this->organization, MembershipRole::Staff), 'offene Einladung');

        $this->assertDatabaseCount('invitations', 1);
        Mail::assertSent(PortalInvitation::class, 1);
    }

    public function test_duplicate_internal_invitation_is_blocked(): void
    {
        $this->access->invite($this->admin, 'person@example.test', internalRole: InternalRole::Caseworker);

        $this->assertRejected(fn () => $this->access->invite(User::factory()->globalAdmin()->create(), 'person@example.test', internalRole: InternalRole::Caseworker), 'offene Einladung');

        $this->assertDatabaseCount('invitations', 1);
        Mail::assertSent(PortalInvitation::class, 1);
    }

    public static function completedInvitations(): array
    {
        return [
            'revoked' => ['revoked_at', 0],
            'accepted' => ['accepted_at', 0],
            'expired' => ['expires_at', -1],
        ];
    }

    #[DataProvider('completedInvitations')]
    public function test_completed_invitation_does_not_prevent_a_new_invitation(string $column, int $offset): void
    {
        $invitation = $this->access->invite($this->admin, 'person@example.test', $this->organization, MembershipRole::Staff);
        $invitation->update([$column => now()->addSeconds($offset)]);

        $replacement = $this->access->invite($this->admin, 'person@example.test', $this->organization, MembershipRole::Staff);

        $this->assertNotSame($invitation->id, $replacement->id);
        $this->assertDatabaseCount('invitations', 2);
        Mail::assertSent(PortalInvitation::class, 2);
    }

    public function test_different_organizations_and_roles_are_not_duplicate_grants(): void
    {
        $this->isolateLimit('recipient_per_hour', 10);
        $this->access->invite($this->admin, 'person@example.test', $this->organization, MembershipRole::Staff);
        $this->access->invite($this->admin, 'person@example.test', $this->organization, MembershipRole::Manager);
        $this->access->invite($this->admin, 'person@example.test', Organization::factory()->create(), MembershipRole::Staff);
        $this->access->invite($this->admin, 'person@example.test', internalRole: InternalRole::Caseworker);
        $this->access->invite($this->admin, 'person@example.test', internalRole: InternalRole::GlobalAdmin);

        $this->assertDatabaseCount('invitations', 5);
        Mail::assertSent(PortalInvitation::class, 5);
    }

    public function test_shared_database_lock_prevents_a_second_cache_client_from_issuing(): void
    {
        $lock = Cache::store('database')->lock('portal:invitations:issue', 30);
        $this->assertTrue($lock->get());
        Cache::purge('database');
        try {
            $this->assertRejected(fn () => $this->access->invite($this->admin, 'person@example.test', $this->organization, MembershipRole::Staff), 'ausgelastet');
            $this->assertDatabaseCount('invitations', 0);
            Mail::assertNothingSent();
        } finally {
            $lock->release();
        }

        $this->access->invite($this->admin, 'person@example.test', $this->organization, MembershipRole::Staff);
        $this->assertDatabaseCount('invitations', 1);
        Mail::assertSent(PortalInvitation::class, 1);
    }

    public static function sharedInternalLimits(): array
    {
        return ['actor' => ['actor_per_minute', false], 'global' => ['global_per_minute', true]];
    }

    #[DataProvider('sharedInternalLimits')]
    public function test_internal_invitations_share_limits_with_organization_invitations(string $limit, bool $changeActor): void
    {
        $this->isolateLimit($limit, 1);
        $this->access->invite($this->admin, 'first@example.test', internalRole: InternalRole::Caseworker);
        $actor = $changeActor ? User::factory()->globalAdmin()->create() : $this->admin;

        $this->assertRejected(fn () => $this->access->invite($actor, 'second@example.test', $this->organization, MembershipRole::Staff), 'Versandlimit');

        $this->assertDatabaseCount('invitations', 1);
        Mail::assertSent(PortalInvitation::class, 1);
    }

    public function test_quotas_are_reserved_and_the_issuance_lock_is_released_before_delivery(): void
    {
        $this->isolateLimit('global_per_minute', 1);
        Mail::shouldReceive('to')->once()->andReturnSelf();
        Mail::shouldReceive('send')->once()->andReturnUsing(function (): void {
            Cache::purge('database');
            $this->assertRejected(fn () => $this->access->invite(User::factory()->caseworker()->create(), 'second@example.test', $this->organization, MembershipRole::Staff), 'Versandlimit');
        });

        $this->access->invite($this->admin, 'first@example.test', $this->organization, MembershipRole::Staff);

        $this->assertDatabaseCount('invitations', 1);
    }

    public function test_rejected_attempt_does_not_consume_other_available_quotas(): void
    {
        $this->isolateLimit('actor_per_minute', 1);
        config(['invitations.limits.organization_per_hour' => 2]);
        $this->access->invite($this->admin, 'first@example.test', $this->organization, MembershipRole::Staff);
        $this->assertRejected(fn () => $this->access->invite($this->admin, 'second@example.test', $this->organization, MembershipRole::Staff), 'Versandlimit');

        $this->access->invite(User::factory()->caseworker()->create(), 'second@example.test', $this->organization, MembershipRole::Staff);

        $this->assertDatabaseCount('invitations', 2);
        Mail::assertSent(PortalInvitation::class, 2);
    }

    public function test_default_actor_minute_limit_is_ten_and_applies_to_global_admins(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->access->invite($this->admin, "person{$i}@example.test", $this->organization, MembershipRole::Staff);
        }
        $this->assertRejected(fn () => $this->access->invite($this->admin, 'eleventh@example.test', $this->organization, MembershipRole::Staff), '1 Minute');

        $this->assertDatabaseCount('invitations', 10);
        Mail::assertSent(PortalInvitation::class, 10);
    }

    public function test_invalid_and_unauthorized_requests_do_not_allocate_or_consume_quota(): void
    {
        $this->isolateLimit('global_per_minute', 1);
        $this->assertRejected(fn () => $this->access->invite($this->admin, 'not-an-email', $this->organization, MembershipRole::Staff), '');
        try {
            $this->access->invite(User::factory()->create(), 'person@example.test', $this->organization, MembershipRole::Staff);
            $this->fail('Unauthorized invitation was allowed.');
        } catch (AuthorizationException) {
            $this->assertDatabaseCount('invitations', 0);
            Mail::assertNothingSent();
        }

        $this->access->invite($this->admin, 'person@example.test', $this->organization, MembershipRole::Staff);
        $this->assertDatabaseCount('invitations', 1);
        Mail::assertSent(PortalInvitation::class, 1);
    }

    private function isolateLimit(string $limit, int $maximum): void
    {
        foreach (array_keys(config('invitations.limits')) as $key) {
            config(["invitations.limits.{$key}" => 1000]);
        }
        config(["invitations.limits.{$limit}" => $maximum]);
    }

    /** @return array{User, Organization, string} */
    private function invitationContext(string $scope, int $index): array
    {
        return [
            $scope === 'actor' ? $this->admin : User::factory()->caseworker()->create(),
            $scope === 'organization' ? $this->organization : Organization::factory()->create(),
            $scope === 'recipient' ? ($index % 2 === 0 ? ' PERSON@EXAMPLE.TEST ' : 'person@example.test') : "person{$index}@example.test",
        ];
    }

    private function assertRejected(callable $attempt, string $message): void
    {
        try {
            $attempt();
            $this->fail('Invitation was not rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('email', $exception->errors());
            $this->assertStringContainsString($message, $exception->errors()['email'][0]);
        }
    }
}
