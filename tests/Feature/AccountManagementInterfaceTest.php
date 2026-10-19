<?php

namespace Tests\Feature;

use App\Enums\InternalRole;
use App\Filament\Pages\Accounts;
use App\Filament\Pages\CreateAccount;
use App\Filament\Pages\EditAccount;
use App\Models\User;
use App\Notifications\PortalVerifyEmail;
use App\Services\PortalAccess;
use Filament\Actions\Testing\TestAction;
use Filament\Auth\Pages\EmailVerification\EmailVerificationPrompt;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AccountManagementInterfaceTest extends TestCase
{
    use RefreshDatabase;

    private function adminPanel(User $user): void
    {
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::bootCurrentPanel();
        Filament::setTenant(null);
    }

    /** @return array{name: string, email: string, password: string, password_confirmation: string} */
    private function accountData(): array
    {
        return ['name' => 'Neue Person', 'email' => 'new@example.test', 'password' => 'Safe-Account-Password!', 'password_confirmation' => 'Safe-Account-Password!'];
    }

    public function test_account_list_links_to_both_pages_and_keeps_permissions_action(): void
    {
        $this->adminPanel(User::factory()->globalAdmin()->create());
        $target = User::factory()->create();
        Livewire::test(Accounts::class)->assertSee('Konto anlegen')->assertSee('Konto bearbeiten')
            ->assertActionHasUrl('create', CreateAccount::getUrl(panel: 'admin'))
            ->assertActionHasUrl(TestAction::make('edit')->table($target), EditAccount::getUrl(['user' => $target->id], panel: 'admin'))
            ->assertActionExists(TestAction::make('access')->table($target));

        $this->get('/admin/konten/anlegen')->assertOk()->assertSee('Abbrechen');
        $this->get('/admin/konten/'.$target->id.'/bearbeiten')->assertOk()->assertSee('Kontoinformationen')->assertSee('Angelegt am');
        $this->assertFalse(CreateAccount::shouldRegisterNavigation());
        $this->assertFalse(EditAccount::shouldRegisterNavigation());
        $this->assertNotContains(CreateAccount::class, Filament::getPanel('portal')->getPages());
        $this->assertNotContains(EditAccount::class, Filament::getPanel('portal')->getPages());
    }

    public function test_creation_defaults_to_normal_user_verification_and_redirects_to_edit(): void
    {
        Notification::fake();
        $this->adminPanel(User::factory()->globalAdmin()->create());
        $page = Livewire::test(CreateAccount::class)->assertSet('data.email_verified', false)->assertSet('data.is_active', true)
            ->assertSee('Normalerweise bestätigt die Person ihre E-Mail-Adresse selbst.')
            ->fillForm([...$this->accountData(), 'email' => ' NEW@example.test '])->call('save')->assertHasNoFormErrors()->assertNotified('Konto angelegt');
        $user = User::query()->where('email', 'new@example.test')->firstOrFail();
        $page->assertRedirect(EditAccount::getUrl(['user' => $user->id], panel: 'admin'));
        $this->assertFalse($user->hasVerifiedEmail());
        $this->assertNull($user->internal_role);
        $this->assertTrue(Hash::check('Safe-Account-Password!', $user->password));
        Notification::assertNotSentTo($user, PortalVerifyEmail::class);
    }

    public function test_admin_created_unverified_user_can_request_and_complete_normal_email_verification(): void
    {
        Notification::fake();
        $user = app(PortalAccess::class)->createAccount(User::factory()->globalAdmin()->create(), $this->accountData());
        $this->actingAs($user);
        $this->get('/zugang')->assertRedirect(route('verification.notice'));
        $this->get(route('verification.notice'))->assertRedirect(route('filament.portal.auth.email-verification.prompt'));
        Filament::setCurrentPanel(Filament::getPanel('portal'));
        Filament::bootCurrentPanel();

        Livewire::test(EmailVerificationPrompt::class)->callAction('resendNotification')->assertHasNoActionErrors();
        $url = null;
        Notification::assertSentTo($user, PortalVerifyEmail::class, function (PortalVerifyEmail $notification) use (&$url): bool {
            $url = $notification->url;

            return filled($url);
        });
        $this->get($url)->assertRedirect();
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
        $this->get('/zugang')->assertOk();
    }

    public function test_creation_form_requires_password_and_confirmation_and_validates_email(): void
    {
        $this->adminPanel(User::factory()->globalAdmin()->create());
        $page = Livewire::test(CreateAccount::class)->call('save')
            ->assertHasFormErrors(['name' => 'required', 'email' => 'required', 'password' => 'required', 'password_confirmation' => 'required']);
        $page->fillForm([...$this->accountData(), 'password' => 'short', 'password_confirmation' => 'different', 'email' => 'invalid'])->call('save')
            ->assertHasFormErrors(['email' => 'email', 'password' => 'min']);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_duplicate_email_is_rejected_on_both_forms(): void
    {
        $this->adminPanel(User::factory()->globalAdmin()->create());
        $existing = User::factory()->create(['email' => 'existing@example.test']);
        $target = User::factory()->create();
        Livewire::test(CreateAccount::class)->fillForm([...$this->accountData(), 'email' => ' EXISTING@example.test '])
            ->call('save')->assertHasFormErrors(['email' => 'unique']);
        Livewire::test(EditAccount::class, ['user' => $target->id])->fillForm(['name' => 'Rejected', 'email' => strtoupper($existing->email)])
            ->call('save')->assertHasFormErrors(['email' => 'unique']);
        $this->assertNotSame('Rejected', $target->fresh()->name);
        $this->assertDatabaseCount('users', 3);
    }

    public function test_edit_verifies_account_and_keeps_optional_password_blank(): void
    {
        $this->adminPanel(User::factory()->globalAdmin()->create());
        $target = User::factory()->unverified()->create();
        $hash = $target->password;
        Livewire::test(EditAccount::class, ['user' => $target->id])->assertSet('data.password', null)
            ->assertDontSee($hash)->fillForm(['name' => 'Updated', 'email_verified' => true])
            ->call('save')->assertHasNoFormErrors()->assertNotified('Gespeichert')->assertNoRedirect()->assertSet('data.password', null);
        $this->assertTrue($target->fresh()->hasVerifiedEmail());
        $this->assertSame('Updated', $target->fresh()->name);
        $this->assertSame($hash, $target->fresh()->password);
    }

    public function test_edit_replaces_password_and_clears_sensitive_form_fields_after_save(): void
    {
        $this->adminPanel(User::factory()->globalAdmin()->create());
        $target = User::factory()->create();
        Livewire::test(EditAccount::class, ['user' => $target->id])->fillForm(['password' => 'Replacement-Password!', 'password_confirmation' => 'Replacement-Password!'])
            ->call('save')->assertHasNoFormErrors()->assertSet('data.password', null)->assertSet('data.password_confirmation', null);
        $this->assertTrue(Hash::check('Replacement-Password!', $target->fresh()->password));
    }

    public function test_email_changes_reset_toggle_and_allow_explicit_confirmation_of_new_address(): void
    {
        $this->adminPanel(User::factory()->globalAdmin()->create());
        $target = User::factory()->create();
        $page = Livewire::test(EditAccount::class, ['user' => $target->id])->assertSet('data.email_verified', true)
            ->set('data.email', 'changed@example.test')->assertSet('data.email_verified', false)
            ->call('save')->assertHasNoFormErrors();
        $this->assertFalse($target->fresh()->hasVerifiedEmail());
        $page->set('data.email', 'confirmed@example.test')->set('data.email_verified', true)->call('save')->assertHasNoFormErrors();
        $this->assertTrue($target->fresh()->hasVerifiedEmail());
        $this->assertSame('confirmed@example.test', $target->fresh()->email);
    }

    public function test_verification_can_be_revoked(): void
    {
        $this->adminPanel(User::factory()->globalAdmin()->create());
        $target = User::factory()->create();
        Livewire::test(EditAccount::class, ['user' => $target->id])->fillForm(['email_verified' => false])->call('save')->assertHasNoFormErrors();
        $this->assertFalse($target->fresh()->hasVerifiedEmail());
    }

    public function test_last_admin_error_is_visible_and_all_changes_are_rejected(): void
    {
        $admin = User::factory()->globalAdmin()->create();
        $this->adminPanel($admin);
        $original = $admin->fresh()->getAttributes();
        Livewire::test(EditAccount::class, ['user' => $admin->id])->fillForm(['name' => 'Rejected', 'internal_role' => null, 'email_verified' => false])
            ->call('save')->assertHasFormErrors(['internal_role'])->assertSee('Der letzte aktive Globaladmin muss erhalten bleiben.');
        $this->assertSame($original, $admin->fresh()->getAttributes());
    }

    public function test_direct_promotion_is_rejected_with_form_error(): void
    {
        $this->adminPanel(User::factory()->globalAdmin()->create());
        $target = User::factory()->unverified()->create();
        Livewire::test(EditAccount::class, ['user' => $target->id])->fillForm(['name' => 'Rejected', 'email_verified' => true, 'internal_role' => 'caseworker'])
            ->call('save')->assertHasFormErrors(['internal_role']);
        $this->assertNotSame('Rejected', $target->fresh()->name);
        $this->assertFalse($target->fresh()->hasVerifiedEmail());
        $this->assertNull($target->fresh()->internal_role);
    }

    public function test_admin_self_demotion_redirects_to_access_page_when_another_admin_remains(): void
    {
        $admin = User::factory()->globalAdmin()->create();
        User::factory()->globalAdmin()->create();
        $this->adminPanel($admin);
        Livewire::test(EditAccount::class, ['user' => $admin->id])->fillForm(['internal_role' => null])->call('save')
            ->assertHasNoFormErrors()->assertRedirect(route('access'));
        $this->assertNull($admin->fresh()->internal_role);
    }

    public function test_selected_account_id_cannot_be_modified_by_client(): void
    {
        $this->adminPanel(User::factory()->globalAdmin()->create());
        $target = User::factory()->create();
        $other = User::factory()->create();
        $page = Livewire::test(EditAccount::class, ['user' => $target->id]);
        $this->expectException(CannotUpdateLockedPropertyException::class);
        $page->set('userId', $other->id);
    }

    public function test_missing_account_returns_not_found(): void
    {
        $this->actingAs(User::factory()->globalAdmin()->create())->get('/admin/konten/999999/bearbeiten')->assertNotFound();
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/admin/konten/anlegen')->assertRedirect('/admin/login');
        $this->get('/admin/konten/1/bearbeiten')->assertRedirect('/admin/login');
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
    public function test_unauthorized_accounts_cannot_open_either_page(array $attributes): void
    {
        $actor = User::factory()->globalAdmin()->create($attributes);
        $target = User::factory()->create();
        $this->actingAs($actor)->get('/admin/konten/anlegen')->assertForbidden();
        $this->get('/admin/konten/'.$target->id.'/bearbeiten')->assertForbidden();
    }

    #[DataProvider('unauthorizedActors')]
    public function test_open_forms_recheck_permissions_before_writing(array $attributes): void
    {
        $admin = User::factory()->globalAdmin()->create();
        $this->adminPanel($admin);
        $target = User::factory()->create();
        $create = Livewire::test(CreateAccount::class)->fillForm($this->accountData());
        $edit = Livewire::test(EditAccount::class, ['user' => $target->id])->fillForm(['name' => 'Rejected']);
        $admin->fresh()->forceFill($attributes)->save();
        $create->call('save')->assertForbidden();
        $edit->call('save')->assertForbidden();
        $this->assertNotSame('Rejected', $target->fresh()->name);
        $this->assertDatabaseCount('users', 2);
    }
}
