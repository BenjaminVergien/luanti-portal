<?php

namespace Database\Seeders;

use App\Enums\InternalRole;
use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

class LocalDevelopmentSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local')) {
            throw new RuntimeException('Development accounts may only be created locally.');
        }

        $password = config('development.admin_password');

        if (! is_string($password) || strlen($password) < 16) {
            throw new RuntimeException('Set DEV_ADMIN_PASSWORD in the local .env (at least 16 characters).');
        }

        $user = User::firstOrCreate(
            ['email' => config('development.admin_email')],
            [
                'name' => 'Lokale Entwicklung',
                'password' => $password,
                'email_verified_at' => now(),
            ],
        );
        $user->forceFill(['is_active' => true, 'internal_role' => InternalRole::GlobalAdmin])->save();
    }
}
