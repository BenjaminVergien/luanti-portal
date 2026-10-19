<?php

use App\Enums\WorldRequestStatus;
use App\Jobs\CleanupLuantiDns;
use App\Jobs\ProvisionLuantiBridge;
use App\Jobs\ProvisionWorldRequest;
use App\Jobs\TerminateWorldRequest;
use App\Models\LuantiServer;
use App\Models\WorldRequest;
use App\Services\WorldRequestMailings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Schedule::command('luanti:accounts:recover')->everyMinute()->withoutOverlapping();
Schedule::command('luanti:games:reconcile')->everyMinute()->withoutOverlapping();
Schedule::call(function (): void {
    WorldRequest::query()->where('status', WorldRequestStatus::Approved)->whereNull('approved_access')
        ->whereNotNull('provisioning_configuration')->whereNull('provisioning_error')
        ->where(fn (Builder $query): Builder => $query->whereNull('provisioning_starts_at')->orWhere('provisioning_starts_at', '<=', now()))
        ->each(fn (WorldRequest $request) => ProvisionWorldRequest::dispatch($request->id));
})->name('world-request-provisioning-recovery')->everyMinute()->withoutOverlapping();
Schedule::call(function (): void {
    WorldRequest::query()->where('status', WorldRequestStatus::Terminated)
        ->whereHas('provisionedServer', fn (Builder $query): Builder => $query->where('hetzner_status', '<>', 'deleted'))
        ->each(fn (WorldRequest $request) => TerminateWorldRequest::dispatch($request->id));
})->name('world-request-termination-recovery')->everyMinute()->withoutOverlapping();
Schedule::call(fn () => app(WorldRequestMailings::class)->recover())
    ->name('world-request-mail-recovery')->everyMinute()->withoutOverlapping();
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
