<?php

namespace App\Jobs;

use App\Models\LuantiServer;
use App\Services\HetznerDns;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Throwable;

class CleanupLuantiDns implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 180;

    public int $uniqueFor = 1000;

    public function __construct(public int $serverId) {}

    public function uniqueId(): string
    {
        return (string) $this->serverId;
    }

    /**
     * Execute the job.
     */
    public function handle(HetznerDns $dns): void
    {
        Cache::lock('hetzner:vm:'.$this->serverId, 200)->get(function () use ($dns): void {
            $server = LuantiServer::query()->find($this->serverId);
            if ($server === null || $server->hetzner_status !== 'deleted' || ! $server->dns_cleanup_pending
                || $server->dns_cleanup_attempts >= 12 || $server->dns_cleanup_next_at?->isFuture()) {
                return;
            }
            $server->forceFill(['dns_cleanup_attempts' => $server->dns_cleanup_attempts + 1])->save();
            try {
                $dns->cleanup($server);
            } catch (Throwable $exception) {
                $permanent = $exception instanceof ValidationException;
                $server->forceFill(['dns_status' => 'cleanup_failed',
                    'dns_error' => $permanent ? collect($exception->errors())->flatten()->first() : 'DNS-Bereinigung noch nicht bestätigt. Bitte erneut versuchen.',
                    'dns_cleanup_attempts' => $permanent ? 12 : $server->dns_cleanup_attempts,
                    'dns_cleanup_next_at' => now()->addSeconds(min(900, 30 * 2 ** min($server->dns_cleanup_attempts - 1, 5))),
                ])->save();
            }
            if ($server->dns_cleanup_pending && $server->dns_cleanup_attempts < 12) {
                self::dispatch($server->id)->delay($server->dns_cleanup_next_at ?? now()->addSeconds(30));
            }
        });
    }
}
