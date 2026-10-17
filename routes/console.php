<?php

use App\Jobs\CleanupLuantiDns;
use App\Jobs\ProvisionLuantiBridge;
use App\Models\LuantiServer;
use Illuminate\Database\Eloquent\Builder;
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

Schedule::call(function (): void {
    LuantiServer::query()->where('hetzner_status', 'deleted')->where('dns_cleanup_pending', true)
        ->where('dns_cleanup_attempts', '<', 12)
        ->where(fn (Builder $query): Builder => $query->whereNull('dns_cleanup_next_at')->orWhere('dns_cleanup_next_at', '<=', now()))
        ->each(fn (LuantiServer $server) => CleanupLuantiDns::dispatch($server->id));
})->name('dns-cleanup-recovery')->everyMinute()->withoutOverlapping();

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
