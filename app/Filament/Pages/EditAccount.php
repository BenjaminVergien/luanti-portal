<?php

namespace App\Filament\Pages;

use App\Enums\InternalRole;
use App\Models\User;
use App\Services\PortalAccess;
use Filament\Notifications\Notification;
use Livewire\Attributes\Locked;

class EditAccount extends AccountFormPage
{
    protected static ?string $title = 'Konto bearbeiten';

    protected static ?string $slug = 'konten/{user}/bearbeiten';

    protected string $view = 'filament.pages.edit-account';

    #[Locked]
    public int $userId;

    public function mount(int $user): void
    {
        $this->userId = $user;
        $this->fillAccountForm();
    }

    protected function account(): ?User
    {
        return User::query()->findOrFail($this->userId);
    }

    protected function persist(#[\SensitiveParameter] array $data): void
    {
        $actor = $this->actor();
        app(PortalAccess::class)->updateAccount($actor, $this->account(), filled($data['internal_role'] ?? null) ? InternalRole::from($data['internal_role']) : null, (bool) $data['is_active'], $data);
        Notification::make()->title('Gespeichert')->success()->send();

        if (! $actor->fresh()->isGlobalAdmin()) {
            $this->redirect(route('access'));

            return;
        }

        $this->fillAccountForm();
    }

    private function fillAccountForm(): void
    {
        $account = $this->account();
        $this->form->fill([
            'name' => $account->name,
            'email' => $account->email,
            'is_active' => $account->is_active,
            'email_verified' => $account->hasVerifiedEmail(),
            'internal_role' => $account->internal_role?->value,
        ]);
    }
}
