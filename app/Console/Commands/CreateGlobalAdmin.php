<?php

namespace App\Console\Commands;

use App\Enums\InternalRole;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class CreateGlobalAdmin extends Command
{
    protected $signature = 'admin:create';

    protected $description = 'Den ersten Globaladmin interaktiv einrichten';

    public function handle(): int
    {
        if (! $this->input->isInteractive()) {
            $this->error('Dieser Befehl benötigt interaktive Eingaben.');

            return self::FAILURE;
        }
        if ($this->hasAdmin()) {
            $this->error('Ein aktiver Globaladmin existiert bereits. Weitere interne Rollen bitte per Einladung vergeben.');

            return self::FAILURE;
        }
        $email = Str::lower(trim((string) $this->ask('E-Mail-Adresse')));
        $validator = Validator::make(['email' => $email], ['email' => ['required', 'email', 'max:255']]);
        if ($validator->fails()) {
            $this->error('Bitte eine gültige E-Mail-Adresse angeben.');

            return self::FAILURE;
        }
        $user = User::query()->where('email', $email)->first();
        if ($user !== null) {
            if (! $this->confirm('Das bestehende Konto zum ersten Globaladmin ernennen?')) {
                return self::FAILURE;
            }
        } else {
            $name = $this->ask('Name');
            $password = $this->secret('Passwort (mindestens 16 Zeichen)');
            $confirmation = $this->secret('Passwort wiederholen');
            $validator = Validator::make(['name' => $name, 'password' => $password, 'password_confirmation' => $confirmation],
                ['name' => ['required', 'string', 'max:255'], 'password' => ['required', 'string', 'min:16', 'confirmed']]);
            if ($validator->fails()) {
                $this->error('Name oder Passwort ungültig; mindestens 16 Zeichen und gleiche Passwortwiederholung erforderlich.');

                return self::FAILURE;
            }
            $user = new User(['name' => $name, 'email' => $email, 'password' => $password]);
        }
        DB::transaction(function () use ($user): void {
            if ($this->hasAdmin()) {
                throw new \RuntimeException('Inzwischen wurde bereits ein Globaladmin angelegt.');
            }
            $user->forceFill(['internal_role' => InternalRole::GlobalAdmin, 'is_active' => true, 'email_verified_at' => $user->email_verified_at ?? now()])->save();
        });
        $this->info('Der erste Globaladmin wurde eingerichtet.');

        return self::SUCCESS;
    }

    private function hasAdmin(): bool
    {
        return User::query()->where('internal_role', InternalRole::GlobalAdmin)->where('is_active', true)->whereNotNull('email_verified_at')->exists();
    }
}
