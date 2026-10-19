<?php

namespace App\Jobs;

use App\Models\LuantiServer;
use App\Services\BridgeProvisioning;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProvisionLuantiBridge implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $timeout = 1200;

    public int $tries = 1;

    public int $uniqueFor = 1300;

    public function __construct(public int $serverId) {}

    public function uniqueId(): string
    {
        return (string) $this->serverId;
    }

    /**
     * Execute the job.
     */
    public function handle(BridgeProvisioning $provisioning): void
    {
        if ($provisioning->process($this->serverId)) {
            $server = LuantiServer::query()->find($this->serverId);
            self::dispatch($this->serverId)->delay($server?->public_provisioning_next_at ?? now()->addSeconds(30));

            return;
        }
        $server = LuantiServer::query()->find($this->serverId);
        if ($server?->world_request_id !== null && $server->bridge_status === 'installed'
            && $server->luanti_installation_status === 'verified' && $server->hetzner_status === 'running') {
            ProvisionWorldRequest::dispatch($server->world_request_id)->afterCommit();
        }
    }
}
