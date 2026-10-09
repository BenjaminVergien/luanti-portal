<?php

namespace Tests\Feature;

use App\Models\User;
use Filament\Auth\Pages\Login;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class FilamentPanelTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_panel_login(): void
    {
        $this->get('/admin')->assertRedirect('/admin/login');
        $this->get('/admin/login')->assertOk();
    }

    public function test_an_explicit_local_admin_can_sign_in_and_open_the_dashboard(): void
    {
        $this->app->instance('env', 'local');
        config(['app.env' => 'local']);

        $user = User::factory()->globalAdmin()->create(['password' => 'test-only-password']);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::bootCurrentPanel();

        Livewire::test(Login::class)
            ->set('data.email', $user->email)
            ->set('data.password', 'test-only-password')
            ->call('authenticate')
            ->assertHasNoFormErrors();

        $this->assertAuthenticatedAs($user);
        $this->get('/admin')->assertOk();
    }

    public function test_the_local_account_model_does_not_grant_production_panel_access(): void
    {
        $this->app->instance('env', 'production');
        config(['app.env' => 'production']);

        $this->actingAs(User::factory()->create())
            ->get('/admin')
            ->assertForbidden();
    }
}
