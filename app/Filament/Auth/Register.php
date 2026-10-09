<?php

namespace App\Filament\Auth;

use Filament\Auth\Pages\Register as BaseRegister;
use Filament\Schemas\Components\Component;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Register extends BaseRegister
{
    protected function getEmailFormComponent(): Component
    {
        return parent::getEmailFormComponent()
            ->mutateStateForValidationUsing(fn (string $state): string => Str::lower(trim($state)))
            ->dehydrateStateUsing(fn (string $state): string => Str::lower(trim($state)));
    }

    protected function getPasswordFormComponent(): Component
    {
        return parent::getPasswordFormComponent()->minLength(12);
    }

    protected function handleRegistration(#[\SensitiveParameter] array $data): Model
    {
        return parent::handleRegistration(['name' => $data['name'], 'email' => Str::lower(trim($data['email'])), 'password' => $data['password']]);
    }
}
