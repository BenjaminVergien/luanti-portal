<?php

namespace App\Filament\Pages;

use App\Services\PortalAccess;
use Filament\Notifications\Notification;

class CreateAccount extends AccountFormPage
{
    protected static ?string $title = 'Konto anlegen';

    protected static ?string $slug = 'konten/anlegen';

    protected string $view = 'filament.pages.create-account';

    public function mount(): void
    {
        $this->form->fill();
    }

    protected function persist(#[\SensitiveParameter] array $data): void
    {
        $user = app(PortalAccess::class)->createAccount($this->actor(), $data);
        Notification::make()->title('Konto angelegt')->success()->send();
        $this->redirect(EditAccount::getUrl(['user' => $user->id], panel: 'admin'));
    }
}
