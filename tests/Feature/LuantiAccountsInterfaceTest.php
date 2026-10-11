<?php

namespace Tests\Feature;

use App\Filament\Pages\LuantiAccounts;
use App\Filament\Pages\WorldRequests;
use App\Models\LuantiAccount;
use App\Models\LuantiAccountJob;
use App\Models\User;
use App\Models\WorldRequest;
use App\Services\LuantiAccounts as LuantiAccountService;
use App\Services\WorldRequests as WorldRequestService;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LuantiAccountsInterfaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::preventStrayRequests();
    }

    private function panel(User $user): void
    {
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::bootCurrentPanel();
        Filament::setTenant(null);
    }

    public function test_internal_staff_create_multiple_game_accounts_and_clear_password_form(): void
    {
        $this->panel(User::factory()->caseworker()->create());
        $request = WorldRequest::factory()->approved()->create();
        $before = User::query()->count();
        Livewire::test(LuantiAccounts::class, ['worldRequest' => $request->id])
            ->fillForm(['accounts' => [
                ['username' => 'Teacher_1', 'password' => 'Teacher-Password-123', 'role' => 'facilitator'],
                ['username' => 'Player-2', 'password' => 'Player-Password-123', 'role' => 'participant'],
            ]])->call('save')->assertHasNoFormErrors()->assertSet('data.accounts', [])
            ->assertSee('Teacher_1')->assertSee('Player-2')->assertSee('Fachkraft')->assertSee('Mitglied')
            ->assertDontSee('Teacher-Password-123')->assertDontSee('Player-Password-123');
        $this->assertDatabaseCount('luanti_accounts', 2);
        $this->assertDatabaseCount('users', $before);
    }

    public function test_password_is_only_shown_in_explicit_modal_and_warns_about_ingame_changes(): void
    {
        $actor = User::factory()->globalAdmin()->create();
        $this->panel($actor);
        $request = WorldRequest::factory()->approved()->create();
        app(LuantiAccountService::class)->create($actor, $request, [['username' => 'Player', 'password' => 'Player-Password-123', 'role' => 'participant']]);
        $account = LuantiAccount::query()->sole();
        $page = Livewire::test(LuantiAccounts::class, ['worldRequest' => $request->id])->assertDontSee('Player-Password-123');
        $page->mountAction(TestAction::make('revealPassword')->table($account))->assertActionMounted(TestAction::make('revealPassword')->table($account));
        $action = $page->instance()->getMountedAction();
        $this->assertStringContainsString('Player-Password-123', $action->getModalContent()->toHtml());
        $this->assertStringContainsString('im Spiel', $action->getModalDescription());
        $page->unmountAction()->assertDontSee('Player-Password-123');
    }

    public function test_request_id_is_locked_against_client_tampering(): void
    {
        $this->panel(User::factory()->globalAdmin()->create());
        $request = WorldRequest::factory()->approved()->create();
        $page = Livewire::test(LuantiAccounts::class, ['worldRequest' => $request->id]);
        $this->expectException(CannotUpdateLockedPropertyException::class);
        $page->set('worldRequestId', $request->id + 1);
    }

    public static function unauthorizedActors(): array
    {
        return ['ordinary user' => [['internal_role' => null]], 'inactive admin' => [['is_active' => false]], 'unverified admin' => [['email_verified_at' => null]]];
    }

    #[DataProvider('unauthorizedActors')]
    public function test_only_active_verified_internal_staff_can_access_game_account_page(array $attributes): void
    {
        $actor = User::factory()->globalAdmin()->create($attributes);
        $request = WorldRequest::factory()->approved()->create();
        $this->actingAs($actor)->get('/admin/weltantraege/'.$request->id.'/spielaccounts')->assertForbidden();
        $this->assertNotContains(LuantiAccounts::class, Filament::getPanel('portal')->getPages());
    }

    public function test_open_form_rechecks_permissions_after_revocation(): void
    {
        $actor = User::factory()->caseworker()->create();
        $this->panel($actor);
        $request = WorldRequest::factory()->approved()->create();
        $page = Livewire::test(LuantiAccounts::class, ['worldRequest' => $request->id])->fillForm(['accounts' => [
            ['username' => 'Player', 'password' => 'Player-Password-123', 'role' => 'participant'],
        ]]);
        $actor->fresh()->forceFill(['is_active' => false])->save();
        $page->call('save')->assertForbidden();
        $this->assertDatabaseCount('luanti_accounts', 0);
    }

    public function test_form_validation_is_visible_for_bad_names_and_short_passwords(): void
    {
        $this->panel(User::factory()->globalAdmin()->create());
        $request = WorldRequest::factory()->approved()->create();
        Livewire::test(LuantiAccounts::class, ['worldRequest' => $request->id])->fillForm(['accounts' => [
            ['username' => 'bad name', 'password' => 'short', 'role' => 'participant'],
        ]])->call('save')->assertHasFormErrors();
        $this->assertDatabaseCount('luanti_accounts', 0);
    }

    public function test_pending_requests_without_prior_approval_have_no_game_account_page(): void
    {
        $this->panel(User::factory()->globalAdmin()->create());
        $request = WorldRequest::factory()->create();
        $this->get('/admin/weltantraege/'.$request->id.'/spielaccounts')->assertNotFound();
        $this->get('/admin/weltantraege/999999/spielaccounts')->assertNotFound();
    }

    public function test_guest_is_redirected_to_internal_login(): void
    {
        $this->get('/admin/weltantraege/1/spielaccounts')->assertRedirect('/admin/login');
    }

    public function test_approved_request_links_to_game_accounts_but_new_pending_request_does_not(): void
    {
        $this->panel(User::factory()->caseworker()->create());
        $approved = WorldRequest::factory()->approved()->create();
        $pending = WorldRequest::factory()->create();
        Livewire::test(WorldRequests::class)
            ->assertActionHasUrl(TestAction::make('spielaccounts')->table($approved), LuantiAccounts::getUrl(['worldRequest' => $approved->id], panel: 'admin'))
            ->assertActionHidden(TestAction::make('spielaccounts')->table($pending));
    }

    public function test_previous_approved_request_can_retry_pending_jobs_while_new_accounts_remain_disabled(): void
    {
        $actor = User::factory()->caseworker()->create();
        $this->panel($actor);
        $request = WorldRequest::factory()->approved()->create();
        app(LuantiAccountService::class)->create($actor, $request, [['username' => 'Player', 'password' => 'Player-Password-123', 'role' => 'participant']]);
        $oldEnd = $request->fresh()->end_date->toDateString();
        app(WorldRequestService::class)->update($actor, $request->fresh(), [...$request->only(['name', 'luanti_world_id', 'participant_count']),
            'start_date' => $request->start_date->toDateString(), 'end_date' => $request->end_date->addDay()->toDateString()], $request->revision);
        $page = Livewire::test(LuantiAccounts::class, ['worldRequest' => $request->id])->assertSee('Zuletzt genehmigter Stand')
            ->assertDontSee('Anlegen und übertragen')->call('retryTransfers')->assertStatus(200);
        $this->assertSame($oldEnd, Carbon::createFromTimestamp($request->fresh()->approved_access['ends_at'], 'Europe/Berlin')->subSecond()->toDateString());
        $page->call('save')->assertForbidden();
    }

    public function test_job_error_is_displayed_without_credentials_or_raw_response(): void
    {
        $actor = User::factory()->globalAdmin()->create();
        $this->panel($actor);
        $request = WorldRequest::factory()->approved()->create();
        $request->world->server->update(['api_base_url' => 'https://bridge.example.test', 'api_token' => 'hidden-credential']);
        app(LuantiAccountService::class)->create($actor, $request, [['username' => 'Player', 'password' => 'Player-Password-123', 'role' => 'participant']]);
        app(LuantiAccountService::class)->reconcile($request->id);
        $job = LuantiAccountJob::query()->sole();
        Http::fake(['*' => Http::response('hidden-credential upstream secret', 400)]);
        app(LuantiAccountService::class)->process($job->id);
        Livewire::test(LuantiAccounts::class, ['worldRequest' => $request->id])->assertSee('Version 2')
            ->assertDontSee('hidden-credential')->assertDontSee('upstream secret')->assertDontSee('Player-Password-123');
    }

    public function test_rejected_uncreated_account_can_be_corrected_without_touching_successful_accounts(): void
    {
        $actor = User::factory()->caseworker()->create();
        $this->panel($actor);
        $request = WorldRequest::factory()->approved()->create();
        $request->world->server->update(['api_base_url' => 'https://bridge.example.test', 'api_token' => 'test-token']);
        app(LuantiAccountService::class)->create($actor, $request, [['username' => 'Reserved', 'password' => 'Player-Password-123', 'role' => 'participant']]);
        app(LuantiAccountService::class)->reconcile($request->id);
        $job = LuantiAccountJob::query()->sole();
        Http::fake(['*' => Http::response(['version' => 1, 'world_id' => $job->world_identifier, 'job_id' => $job->job_id, 'state' => 'completed',
            'result' => ['version' => 2, 'job_id' => $job->job_id, 'status' => 'error', 'accounts' => [
                ['username' => 'Reserved', 'status' => 'rejected', 'code' => 'existing_account'],
            ]]])]);
        app(LuantiAccountService::class)->process($job->id);
        $account = LuantiAccount::query()->sole();
        Livewire::test(LuantiAccounts::class, ['worldRequest' => $request->id])
            ->callAction(TestAction::make('correctRejected')->table($account), ['username' => 'NewPlayer', 'password' => 'New-Password-123'])
            ->assertHasNoActionErrors()->assertSee('NewPlayer')->assertDontSee('New-Password-123');
        $this->assertSame('NewPlayer', $account->fresh()->username);
        $this->assertSame('New-Password-123', app(LuantiAccountService::class)->revealPassword($actor, $account));
        app(LuantiAccountService::class)->reconcile($request->id);
        $retry = LuantiAccountJob::query()->latest('id')->firstOrFail();
        $this->assertNotSame($job->job_id, $retry->job_id);
        $this->assertSame(['NewPlayer'], array_column(json_decode($retry->request_payload, true)['accounts'], 'username'));
    }
}
