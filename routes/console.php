<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Schedule::command('luanti:worlds:sync')->hourly()->withoutOverlapping(10);
Schedule::command('luanti:accounts:recover')->everyMinute()->withoutOverlapping();
Schedule::command('luanti:games:reconcile')->everyMinute()->withoutOverlapping();

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
