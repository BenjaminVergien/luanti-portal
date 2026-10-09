<?php

namespace App\Enums;

enum MembershipRole: string
{
    case Manager = 'manager';
    case Staff = 'staff';

    public function label(): string
    {
        return match ($this) {
            self::Manager => 'Einrichtungsverwaltung', self::Staff => 'Fachkraft'
        };
    }

    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $role): array => [$role->value => $role->label()])->all();
    }
}
