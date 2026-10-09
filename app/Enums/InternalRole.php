<?php

namespace App\Enums;

enum InternalRole: string
{
    case GlobalAdmin = 'global_admin';
    case Caseworker = 'caseworker';

    public function label(): string
    {
        return match ($this) {
            self::GlobalAdmin => 'Globaladmin', self::Caseworker => 'Sachbearbeitung'
        };
    }

    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $role): array => [$role->value => $role->label()])->all();
    }
}
