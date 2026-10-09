<?php

namespace App\Filament\Pages;

use Filament\Pages\Dashboard as BaseDashboard;

class Dashboard extends BaseDashboard
{
    protected static ?string $title = 'Übersicht';

    public static function canAccess(): bool
    {
        return auth()->user()?->fresh()?->isInternal() ?? false;
    }

    protected string $view = 'filament.pages.dashboard';
}
