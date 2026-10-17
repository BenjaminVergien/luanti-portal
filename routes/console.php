<?php

use App\Jobs\ProvisionLuantiBridge;
use App\Models\LuantiServer;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Schedule::command('luanti:worlds:sync')->hourly()->withoutOverlapping(10);
Schedule::command('luanti:accounts:recover')->everyMinute()->withoutOverlapping();
Schedule::command('luanti:games:reconcile')->everyMinute()->withoutOverlapping();
Schedule::call(function (): void {
    LuantiServer::query()->whereIn('bridge_status', ['pending', 'installing'])
        ->whereNotIn('hetzner_status', ['deleted', 'deleting', 'create_failed', 'key_pending'])
        ->each(fn (LuantiServer $server) => ProvisionLuantiBridge::dispatch($server->id));
})->name('bridge-provisioning-recovery')->everyMinute()->withoutOverlapping();

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
